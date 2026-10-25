# Iteração 2 — Lista de backups, download e retenção

**Projeto:** Failsafe Backup (slug `failsafe`) · **Versão alvo:** 0.2.0-dev · **Design escrito em:** 2026-10-24
**Pré-requisito:** Iteração 1 concluída — dump SQL gerado em PHP + zip de ABSPATH em `wp-content/failsafe-backups`, execução de ~9 s, `.sql` de 1.329.789 bytes (1,3 MB) e `.zip` de 37.354.413 bytes (37,0 MB), medidos no container.
**Ambiente de verificação:** WordPress 7.1.3, PHP 8.3.36, containers `failsafe-wordpress-1` e `failsafe-db-1`, site em http://localhost:8080.
**Como ler:** as seções 2 e 3 são decisões (o porquê); 4 a 6 são o contrato executável; 7 e 8 são APIs e segurança; 11 é o que prova que a iteração terminou. Não há código de produção aqui — só assinaturas e a ordem de guarda.

---

## 1. Ponto de partida: o que a Iteração 1 deixa pronto e o que ela impede

Estado real do código, lido em 2026-10-24:

| Arquivo | O que faz hoje |
|---|---|
| `src/failsafe.php` | constantes `FAILSAFE_VERSION`, `FAILSAFE_DIR`, `FAILSAFE_BACKUP_DIR`; requires dos três arquivos de admin |
| `src/admin/setup.php` | em `admin_init`: `wp_mkdir_p` + `.htaccess` com `Require all denied` + `index.php` silencioso |
| `src/admin/dashboard.php` | widget com form POST e `wp_nonce_field`; aviso de sucesso via `$_GET` em `admin_notices` |
| `src/admin/actions.php` | `admin_post_failsafe_run_backup` → `failsafe_create_backup()` → `failsafe_build_sql_dump()` + `failsafe_create_zip()` |

**O bloqueio:** `failsafe_create_backup()` grava **nomes fixos** — `failsafe-backup.sql` e `failsafe-backup.zip`. Cada execução sobrescreve a anterior. Sem nome único não existe lista: existiria no máximo uma linha. A Iteração 2 começa por aqui, e essa é a única mudança incompatível com o estado atual.

Arquivos órfãos com nome fixo: **ignorar, não migrar**. A lista só reconhece o padrão novo, e a retenção não toca no que não reconhece. Custa um `rm` manual uma vez; migrar exigiria inventar a data de um arquivo que pode ser o único backup bom do usuário.

**Segundo ponto:** o handler atual redireciona para `admin_url()` (dashboard) e o aviso lê `$_GET` em qualquer tela do admin. Na Iteração 2 o destino passa a ser a página da lista, e o aviso passa a ser exibido onde faz sentido.

---

## 2. Decisões

### D1 — Página própria no menu, não o widget

Decisão: página de topo via `add_menu_page` (slug `failsafe-backups`, ícone `dashicons-backup`). O widget do dashboard fica como **resumo**: último backup, total em disco, link para a página e o botão “Backup agora”.

Por quê:
- a lista é tabular (data, tamanho, dois downloads, excluir) e não cabe num widget de dashboard;
- o widget é arrastável, colapsável e pode estar minimizado — um botão de excluir ali é alvo instável;
- `add_menu_page` devolve o `hook_suffix`, que dá o ponto de escopo para carregar CSS/JS só na nossa tela; o widget não tem isso;
- o produto precisa de um lugar próprio para crescer nas iterações 3 a 6 (restaurar, agendar, limites).

Custo aceito: mais um item de menu de topo num plugin pequeno. A alternativa (submenu em Ferramentas) foi descartada: esconde o produto de quem não quer procurar.

### D2 — Um backup é um conjunto com nome base

Decisão: nome base `failsafe-AAAAMMDD-HHMMSS`, gerado com `wp_date( 'Ymd-His' )` (fuso do site), com dois arquivos por conjunto:

```
wp-content/failsafe-backups/
├─ failsafe-20261024-153000.zip   conteudo: site inteiro + o .sql do proprio conjunto
└─ failsafe-20261024-153000.sql   conteudo: apenas o dump do banco
```

Regras:
- o `.zip` é o artefato canônico (leva o `.sql` dentro); o `.sql` solto é conveniência para recuperar só o banco;
- a lista agrupa por nome base; excluir apaga os dois;
- colisão no mesmo segundo: sufixo `-2` até `-9`; depois disso, erro;
- o `gmdate( 'c' )` dentro do dump continua sendo o timestamp do conteúdo, não do nome.

Por quê: preserva o comportamento da Iteração 1 (o `.sql` já é o que o handler reporta no aviso) sem criar um terceiro formato, e mantém o dump legível fora do zip.

### D3 — Retenção entra agora, mas sem tela de configuração

Decisão: `failsafe_apply_retention()` roda ao fim de cada backup bem-sucedido, com N vindo de `get_option( 'failsafe_retention_count', 5 )` e filtrável por `failsafe_retention_count`. **Sem** campo de formulário nesta iteração — a tela de configurações é da iteração 4, junto com o agendamento.

Por quê: sem retenção, cada clique deixa ~38 MB no disco, e a tela da Iteração 2 vira uma bomba de disco antes da iteração 4. A parte cara é a regra de apagar, e ela é a mesma; o que fica para depois é só o input.

### D4 — Filesystem API onde há escrita; PHP direto só no streaming

Decisão, baseada em teste no ambiente real:

- **listar**: `$wp_filesystem->dirlist( $dir, false )` — uma chamada, sem abrir arquivo;
- **excluir**: `$wp_filesystem->delete()`;
- **baixar**: `readfile()` direto, sem inicializar o Filesystem API.

Dois fatos medidos, não supostos:

1. `WP_Filesystem()` **sem argumentos falha neste ambiente**: `get_filesystem_method()` devolve `ftpsockets` e a inicialização retorna `false` (os arquivos são de `www-data` e o core conclui que precisaria de FTP). Com `WP_Filesystem( false, FAILSAFE_BACKUP_DIR, true )` — contexto explícito e `$allow_relaxed_file_ownership = true` — a inicialização retorna `true` e a classe é `WP_Filesystem_Direct`. Ou seja: **a chamada precisa dos três argumentos**, senão a listagem e a exclusão param em qualquer host com dono de arquivo diferente do usuário do PHP.
2. `$wp_filesystem->dirlist()` devolve `lastmodunix` (int) e também `lastmod` (string já formatada com `gmdate`, em UTC, sem i18n). Usamos `lastmodunix` com `wp_date()`; `lastmod` daria data errada para o fuso do site.

Por que não usar o Filesystem API no download: `get_contents()` carrega o arquivo inteiro em memória — 37 MB por download, contra um `memory_limit` típico de 256 MB, num produto que se vende justamente por não estourar memória. `readfile()` faz streaming, e o manual do PHP diz que ele não apresenta problema de memória por si, desde que o output buffering esteja desligado.

### D5 — Tabela HTML simples, não `WP_List_Table`

Decisão: `<table class='wp-list-table widefat fixed striped'>` escrito à mão.

Por quê: a documentação da classe avisa que ela é marcada como **private** e que o desenvolvedor a usa por conta e risco, sujeita a mudanças. Para 5 a 10 linhas, sem paginação, busca, ordenação ou bulk actions, a classe custa cerca de dez métodos obrigatórios e não entrega nada que a tabela simples não dê. Paginação também não entra: a retenção fixa o teto natural.

### D6 — Download por `admin-post.php`, verbo GET, nonce na URL

Decisão: link para `admin_url( 'admin-post.php' )` composto com `add_query_arg` e `wp_nonce_url`, ação `failsafe_download_backup`.

Por quê: `admin-post.php` é requisição isolada (sem cabeçalho do admin, sem widgets), já chama `nocache_headers()` antes de disparar a ação e permite `exit` limpo após o stream. Ele resolve a ação a partir de `$_REQUEST['action']`, então GET funciona — conferido no núcleo instalado, não deduzido. Exclusão continua POST, com checagem explícita de método.

### D7 — Nenhum cache da lista

Decisão: sem transient, sem option com a lista. Cada render lê o diretório.

Por quê: ler dez nomes custa microssegundos, e cache de estado de arquivo é a fonte clássica de “apaguei e continua aparecendo”. Antes de listar, `clearstatcache( true )` para o tamanho não vir velho.

---

## 3. Contrato de dados

Nome base válido — a única coisa que o navegador manda na URL, e ela passa por aqui:

```
^failsafe-[0-9]{8}-[0-9]{6}(-[0-9]{1,2})?$
```

Nunca aceitar caminho, nunca `..`, nunca extensão livre: o handler recebe `backup` (nome base) e `tipo` (`zip` ou `sql`), valida os dois e monta o caminho ele mesmo.

Retorno de `failsafe_get_backups()`, ordenado do mais recente para o mais antigo:

```php
array(
    'failsafe-20261024-153000' => array(
        'base'     => 'failsafe-20261024-153000',
        'time'     => 1792889508,   // mtime do zip; se nao houver zip, do sql
        'size'     => 37354413,     // soma do que existe
        'complete' => true,         // zip existe e tem tamanho maior que zero
        'zip'      => array( 'name' => 'failsafe-20261024-153000.zip', 'size' => 37354413, 'mtime' => 1792889508 ),
        'sql'      => array( 'name' => 'failsafe-20261024-153000.sql', 'size' => 1329789, 'mtime' => 1792889508 ),
    ),
)
```

`sql` é `null` quando o dump não está solto (usuário apagou só ele). `complete` é o que decide se o conjunto entra na retenção; conjunto incompleto aparece com aviso e só sai por ação humana.

---

## 4. Arquivos

**Novos**

| Arquivo | Responsabilidade |
|---|---|
| `src/includes/storage.php` | fonte da verdade do diretório: nome base, caminho, listagem, exclusão, retenção, total em disco. Zero HTML, zero `$_GET`. |
| `src/admin/menu.php` | registra a página em `admin_menu`, guarda o hook suffix, carrega o JS só nela. |
| `src/admin/backups-page.php` | render da página: capability, avisos, tabela, estado vazio, total. Só leitura e escaping. |
| `src/admin/download.php` | handler `admin_post_failsafe_download_backup`: valida, emite cabeçalhos, faz o stream. Nada além disso. |
| `src/admin/partials.php` | pedaços reusados: `failsafe_render_backup_button()`, `failsafe_render_row_actions()`. Evita duplicar o form entre widget e página. |
| `src/admin/format.php` | apresentação: `failsafe_format_time()`, `failsafe_format_size()`. |
| `assets/admin.js` | uma função: confirmar exclusão lendo `data-confirm`. Sem JS inline, sem `esc_js`. |

**Alterados**

| Arquivo | Mudança | Por quê |
|---|---|---|
| `src/failsafe.php` | versão `0.2.0-dev`; require de `includes/storage.php` e dos novos arquivos de admin; constante `FAILSAFE_RETENTION_DEFAULT` | ponto único de carga; hoje storage nem existiria |
| `src/admin/actions.php` | `failsafe_create_backup()` passa a gerar nome base e devolver `base`; chama a retenção no fim; redireciona para a página da lista; falha vira aviso, não só `wp_die` | sem nome único não há lista; a retenção só pode rodar depois do sucesso |
| `src/admin/dashboard.php` | widget vira resumo (último backup, total, link) e usa `failsafe_render_backup_button()` | o widget deixa de ser a tela principal |
| `src/admin/setup.php` | sem mudança funcional; opcional: extrair a criação do diretório para `failsafe_maybe_create_backup_dir()`, chamada também por `failsafe_fs()` | a pasta precisa existir antes de qualquer `dirlist` |

O `assets/admin.js` só é enfileirado na nossa tela: o `hook_suffix` devolvido por `add_menu_page` é comparado em `admin_enqueue_scripts`. Nada de JS global no admin.

---

## 5. Funções propostas

### `src/includes/storage.php`

```php
function failsafe_get_backup_dir(): string;
```
Devolve `FAILSAFE_BACKUP_DIR`. Existe para que mover o diretório (iteração 6) seja uma mudança de um lugar só.

```php
function failsafe_fs(): WP_Filesystem_Base|false;
```
Inicializa uma vez (`static`) com `WP_Filesystem( false, failsafe_get_backup_dir(), true )`. Devolve `false` quando o host exigiria credenciais. Quem chama trata `false` como `WP_Error` de código `failsafe_fs_unavailable` e a página mostra aviso — **sem fallback silencioso** para `unlink()`. Falha de infraestrutura precisa ser visível; é o mesmo princípio de “avisar vende mais que quebrar”.

```php
function failsafe_is_valid_backup_name( string $name ): bool;
```
Valida contra o regex da seção 3 e limite de 40 caracteres. É a fronteira de segurança: tudo que vem do request passa por aqui antes de virar caminho.

```php
function failsafe_backup_path( string $base, string $ext ): string|WP_Error;
```
`$ext` restrito a `array( 'zip', 'sql' )`; valida o nome, monta `failsafe_get_backup_dir() . '/' . $base . '.' . $ext` e devolve `WP_Error` `failsafe_invalid_backup` se algo não bater. Nunca recebe caminho.

```php
function failsafe_get_backups(): array;
```
`clearstatcache( true )` → `failsafe_fs()` → `dirlist( $dir, false )` (sem ocultos, então `.htaccess` e `index.php` ficam fora) → agrupa por nome base → ordena por `time` desc. Ignora o que não casar com nome base válido mais extensão `zip` ou `sql`, inclusive `.part` de uma iteração 5 futura. Devolve `array()` — nunca `WP_Error` — quando o diretório não existe ou está vazio: lista vazia não é erro.

```php
function failsafe_get_backup( string $base ): array|WP_Error;
```
Atalho para um conjunto específico; `WP_Error` `failsafe_backup_not_found` se o nome for inválido ou nenhum dos dois arquivos existir. Usado pelo download e pela exclusão.

```php
function failsafe_delete_backup( string $base ): true|WP_Error;
```
Apaga `.zip` e `.sql` do conjunto. `WP_Error` `failsafe_delete_partial` se um dos dois falhar, listando o que sobrou. Detalhe medido: `$wp_filesystem->delete()` devolve `true` para arquivo inexistente — por isso o handler consulta `exists()` antes, para o aviso dizer “excluído” e não “já não existia” por engano.

```php
function failsafe_get_retention_count(): int;
```
`(int) apply_filters( 'failsafe_retention_count', (int) get_option( 'failsafe_retention_count', FAILSAFE_RETENTION_DEFAULT ) )`, preso a `0..50`; `0` significa retenção desligada (nunca apagar). O clamp existe para um filtro errado não zerar o histórico do usuário.

```php
function failsafe_apply_retention( string $keep_base = '' ): array;
```
Se a contagem for `0`, devolve `array()`. Senão: pega só conjuntos `complete`, ordena por `time` desc, mantém os N primeiros, exclui o resto — **sempre** exceto `$keep_base` (o recém-criado) e exceto o mais recente. Devolve os nomes base apagados, para log. Falha de exclusão não interrompe: acumula, escreve em `error_log` e segue. Backup bom não vira erro porque a limpeza falhou.

```php
function failsafe_get_backups_total_size(): int;
```
Soma dos tamanhos, para o rodapé (“3 backups, 112 MB em disco”) e para um aviso futuro de disco.

### `src/admin/actions.php` (alterações)

```php
function failsafe_create_backup( string $base = '' ): array|WP_Error;
```
`$base` vazio gera o nome com `wp_date( 'Ymd-His' )` e resolve colisão. Escreve o `.sql`, cria o `.zip` com o `.sql` dentro e o site inteiro. No fim chama `failsafe_apply_retention( $base )` e devolve `base`, `sql_path`, `zip_path`, `sql_bytes`, `zip_bytes`. O parâmetro opcional mantém a assinatura retrocompatível: a iteração 4 chama `failsafe_create_backup()` do cron sem mudança.

```php
function failsafe_handle_run_backup(): void;
```
Capability → nonce → `failsafe_create_backup()`. Sucesso: redirect para `admin.php?page=failsafe-backups&failsafe_notice=created&failsafe_backup=<base>`. Erro: redirect com `failsafe_notice=error` e o **código** do erro, não a mensagem crua, que pode conter caminho absoluto. Trocar o `wp_die()` atual por redirect mais aviso é o que deixa o usuário ver a lista depois de um erro.

### `src/admin/menu.php`

```php
function failsafe_register_menu(): void;
```
`add_menu_page( __( 'Failsafe Backup', 'failsafe' ), __( 'Failsafe', 'failsafe' ), 'manage_options', 'failsafe-backups', 'failsafe_render_backups_page', 'dashicons-backup' )`, guardando o retorno (`hook_suffix`).

```php
function failsafe_enqueue_admin_assets( string $hook_suffix ): void;
```
Só quando `$hook_suffix` for o nosso: `wp_enqueue_script( 'failsafe-admin', plugins_url( 'assets/admin.js', FAILSAFE_DIR . '/failsafe.php' ), array(), FAILSAFE_VERSION, true )`.

### `src/admin/backups-page.php`

```php
function failsafe_render_backups_page(): void;
```
`current_user_can( 'manage_options' )` — a documentação de `add_menu_page` avisa que o menu apenas esconde; o callback precisa checar de novo. Depois: avisos, título com o botão “Backup agora”, e a tabela ou o estado vazio.

```php
function failsafe_render_backups_table( array $backups ): void;
```
Colunas: Data · Arquivos · Tamanho · Ações. Data com `failsafe_format_time( $backup['time'] )`. Ações: “Baixar (.zip)”, “SQL”, “Excluir” (form POST com nonce por arquivo) e, para conjunto incompleto, selo “incompleto” com explicação curta.

### `src/admin/download.php`

```php
function failsafe_handle_download_backup(): void;
```
A ordem das checagens está na seção 8 e é parte do contrato.

### `src/admin/format.php`

```php
function failsafe_format_time( int $timestamp ): string;  // wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
function failsafe_format_size( int $bytes ): string;      // size_format( $bytes, 1 )
```
O segundo parâmetro importa: medido no ambiente, `size_format( 1363148 )` devolve `1 MB` e `size_format( 1363148, 1 )` devolve `1.3 MB`; o zip de 37.354.413 bytes sai como `37.0 MB` com uma casa. Sem a casa, a diferença entre 1,3 MB e 1 MB — justamente o que o produto quer mostrar — desaparece.

---

## 6. Fluxos

**Listar:** `admin.php?page=failsafe-backups` → capability → `failsafe_get_backups()` → tabela. Nenhuma escrita, nenhum nonce (é leitura).

**Baixar:** link com nonce → `admin-post.php?action=failsafe_download_backup&backup=<base>&tipo=zip&_wpnonce=…` → handler → cabeçalhos → stream → `exit`.

**Excluir:** form POST → `admin-post.php` com ação `failsafe_delete_backup` e nonce `failsafe_delete_backup_<base>` (nonce por arquivo, para um link antigo não apagar outro backup) → `failsafe_delete_backup()` → redirect para a lista com `failsafe_notice=deleted`.

**Retenção:** dentro de `failsafe_create_backup()`, depois de o zip fechar com sucesso. Nunca antes — apagar antes de ter o novo é largar o paraquedas ao pular.

**Avisos:** `failsafe_render_notices()` lê `$_GET['failsafe_notice']` com `sanitize_key( wp_unslash( … ) )`, traduz para uma mensagem fixa por código (`created`, `deleted`, `error`, `delete_failed`) e escapa com `esc_html()`. Nenhuma mensagem de erro do sistema vai para a tela.

---

## 7. APIs do WordPress usadas

| API | Para quê | URL |
|---|---|---|
| `add_menu_page()` | página de topo; o retorno é o hook suffix usado no enqueue | https://developer.wordpress.org/reference/functions/add_menu_page/ |
| `WP_Filesystem()` | inicializar acesso a arquivos com contexto e `allow_relaxed_file_ownership` | https://developer.wordpress.org/reference/classes/wp_filesystem/ |
| `WP_Filesystem_Base::dirlist()` | listar o diretório; devolve `size`, `lastmodunix`, `type` | https://developer.wordpress.org/reference/classes/wp_filesystem_base/ |
| Filesystem API (handbook) | regra de que plugin que escreve arquivo local usa WP_Filesystem | https://developer.wordpress.org/apis/filesystem/ |
| `wp_nonce_url()` | nonce no link de download | https://developer.wordpress.org/reference/functions/wp_nonce_url/ |
| `check_admin_referer()` | verificar intenção no handler (lê `$_REQUEST`) | https://developer.wordpress.org/reference/functions/check_admin_referer/ |
| `wp_verify_nonce()` | validar nonce sem morrer, se precisar | https://developer.wordpress.org/reference/functions/wp_verify_nonce/ |
| `current_user_can()` | autorização real; nonce não autoriza | https://developer.wordpress.org/reference/functions/current_user_can/ |
| `admin_url()` | URL de `admin-post.php` e da página | https://developer.wordpress.org/reference/functions/admin_url/ |
| `add_query_arg()` | compor a query antes do nonce | https://developer.wordpress.org/reference/functions/add_query_arg/ |
| `wp_safe_redirect()` | redirect pós-ação; não sai sozinho, precisa de `exit` | https://developer.wordpress.org/reference/functions/wp_safe_redirect/ |
| `wp_die()` | abortar com status HTTP 400/403/404 | https://developer.wordpress.org/reference/functions/wp_die/ |
| `wp_date()` | data no fuso do site, traduzida | https://developer.wordpress.org/reference/functions/wp_date/ |
| `human_time_diff()` | “há 3 horas” | https://developer.wordpress.org/reference/functions/human_time_diff/ |
| `size_format()` | bytes para MB | https://developer.wordpress.org/reference/functions/size_format/ |
| `wp_delete_file()` | alternativa de exclusão, não usada (ver D4) | https://developer.wordpress.org/reference/functions/wp_delete_file/ |
| `wp_mkdir_p()` | criar o diretório de backups | https://developer.wordpress.org/reference/functions/wp_mkdir_p/ |
| `nocache_headers()` | impedir cache do download; o `admin-post.php` já chama | https://developer.wordpress.org/reference/functions/nocache_headers/ |
| `esc_url()`, `esc_html()`, `esc_attr()` | escaping de saída | https://developer.wordpress.org/reference/functions/esc_url/ |
| `get_option()` | N da retenção | https://developer.wordpress.org/reference/functions/get_option/ |
| `get_current_screen()` | escopo de avisos por tela, se necessário | https://developer.wordpress.org/reference/functions/get_current_screen/ |
| `WP_List_Table` | **não usada** — classe marcada como private | https://developer.wordpress.org/reference/classes/wp_list_table/ |
| Nonces (handbook) | o que o nonce garante e o que não garante | https://developer.wordpress.org/apis/security/nonces/ |
| Escaping (handbook) | escapar na saída, o mais tarde possível | https://developer.wordpress.org/apis/security/escaping/ |
| Data validation (handbook) | validar na entrada | https://developer.wordpress.org/apis/security/data-validation/ |
| `readfile()` | streaming do arquivo | https://www.php.net/manual/en/function.readfile.php |
| WPCS para PHP | padrão de código do plugin | https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/ |

### Pegadinhas verificadas neste ambiente (medidas, não opinião)

1. **`wp_nonce_url()` já escapa.** O fonte é `return esc_html( add_query_arg( … ) )`. Envolver em `esc_url()` produz `&#038;` dentro do valor: medido, `esc_url( wp_nonce_url( $u ) )` devolve `…?action=…&#038;backup=…&#038;_wpnonce=…`, e o parâmetro do nonce deixa de ser parâmetro. Regra: `echo wp_nonce_url( … );` direto, com `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` e um comentário explicando.
2. **`WP_Filesystem()` sem argumentos devolve `false`** aqui (`ftpsockets`); com `( false, $dir, true )` devolve `true` e usa `WP_Filesystem_Direct`.
3. **`dirlist()`**: o timestamp é `lastmodunix`; `lastmod` é string `gmdate( 'M j' )` em UTC. E o default de `$include_hidden` no `WP_Filesystem_Direct` é `true`, enquanto na classe base é `false` — passe `false` explicitamente.
4. **`$wp_filesystem->delete()` devolve `true` para arquivo que não existe.**
5. **`size_format()` arredonda para inteiro por padrão**: `1 MB` em vez de `1.3 MB`.
6. **`check_admin_referer()` morre com “Are You Sure?”** — verifica intenção, não permissão; capability vem antes.
7. **`wp_safe_redirect()` não encerra o script**; sem `exit` o handler continua.
8. **`admin-post.php` resolve a ação por `$_REQUEST`** e responde 400 quando a ação não tem handler registrado — logo, GET serve para download, e `nocache_headers()` já foi chamado antes do handler.
9. **O `.htaccess` funciona neste container**: `curl` direto nos arquivos devolve 403. Isso é Apache com `AllowOverride`; nginx ignora o arquivo.

---

## 8. Segurança ao servir download

| Ameaça | Mitigação |
|---|---|
| Path traversal (`backup=../../wp-config.php`) | nunca aceitar caminho: `backup` é nome base validado por regex, `tipo` é whitelist, o caminho é montado pelo plugin |
| Arquivo fora do diretório mesmo com nome válido (symlink plantado) | `is_link()` recusa; `realpath()` do arquivo tem de começar pelo `realpath()` do diretório |
| CSRF ou link compartilhado | nonce por usuário e por ação, validade de 24 h; GET só para leitura |
| Usuário sem permissão com nonce válido | `current_user_can( 'manage_options' )` antes de tudo |
| Nonce em log ou `Referer` | o nonce de download só autoriza baixar; enviar `Referrer-Policy: no-referrer` na resposta |
| Cache intermediário guardando 37 MB | `nocache_headers()` (já vem do `admin-post.php`) mais `Cache-Control: private, no-store` |
| Sniffing ou execução do `.sql` no navegador | `Content-Type: application/octet-stream`, `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment` |
| Estouro de memória no download | `readfile()` em streaming e `while ( ob_get_level() ) { ob_end_clean(); }` antes |
| `Content-Length` divergente por compressão do Apache | `@ini_set( 'zlib.output_compression', 'Off' )` antes dos cabeçalhos; o tamanho é o do arquivo em disco |
| Enumeração de nomes (timestamp previsível) | a mitigação real é o diretório não ser servível; em nginx os nomes são adivinháveis — risco residual aceito, seção 13 |
| Mensagem de erro vazando caminho absoluto | `wp_die( '', 403 )` ou `wp_die( '', 404 )` sem mensagem; detalhe só em `error_log` |

**Ordem exata do handler de download** (cada passo depende do anterior):

1. `current_user_can( 'manage_options' )` → senão `wp_die( '', 403 )`.
2. `check_admin_referer( 'failsafe_download_backup' )` → morre sozinho se falhar.
3. Ler `backup` e `tipo` de `$_GET` com `sanitize_key( wp_unslash( … ) )`; ausentes → `wp_die( '', 400 )`.
4. `failsafe_backup_path( $base, $tipo )` → `WP_Error` → `wp_die( '', 400 )`.
5. `is_link()`, `realpath()` e comparação de prefixo com o diretório → falhou → `wp_die( '', 403 )`.
6. `is_readable()` e `filesize()` → falhou → `wp_die( '', 404 )`.
7. `nocache_headers()`; `Content-Type` (`application/zip` ou `application/octet-stream`); `Content-Disposition: attachment` com `basename( $real )`; `Content-Length`; `X-Content-Type-Options: nosniff`.
8. Limpar buffers e `readfile( $real )`.
9. `exit`.

Nada de `failsafe_create_backup()` aqui, nada de escrita, nada de HTML.

---

## 9. Retenção

Regra: manter os **N mais recentes conjuntos completos** e apagar os demais, com os dois arquivos de cada um.

Invariantes, cada uma virando teste na seção 11:
- o conjunto recém-criado nunca é apagado, mesmo com N=1 e empate de `mtime`;
- o mais recente nunca é apagado, mesmo que `$keep_base` venha errado;
- conjunto incompleto não conta para N e não é apagado automaticamente;
- N=0 desliga a retenção; N negativo também;
- se a criação falhou, a retenção não roda;
- falha ao apagar não transforma backup bem-sucedido em erro de tela: log mais aviso.

Por que incompleto não conta: se o `.sql` foi apagado à mão, o `.zip` ainda é um backup válido do site inteiro. Tratá-lo como resto e apagá-lo por “ser antigo” seria destruir o único artefato bom. Quem decide é o humano.

---

## 10. i18n, escaping e WPCS

- Text domain `failsafe` em toda string de interface; nomes de arquivo e mensagens de log não são traduzíveis.
- Comentário `/* translators: … */` onde houver `%s` ou `%d` (tamanho, data, contagem).
- Escaping na saída, sempre: `esc_html()` para texto e data, `esc_attr()` para atributos, `esc_url()` para URL sem nonce, `wp_nonce_url()` cru para URL com nonce, `absint()` para contadores.
- Entrada: `sanitize_key()` no que vem de `$_GET`, `absint()` no que for número.
- Docblock em toda função, com `@param` e `@return` (o WPCS exige); `@package Failsafe` no cabeçalho de cada arquivo.
- `vendor/bin/phpcs --standard=WordPress src/` com zero erro. Os avisos que já existem hoje devem continuar zero — não introduzir supressão nova sem comentário justificando.

---

## 11. Critérios de aceitação (testáveis)

Ambiente: `docker compose up -d`, WP em http://localhost:8080, sessão de admin.

**Estrutura e nomes**
1. Dois cliques em “Backup agora” produzem **quatro** arquivos com nomes distintos: `docker exec failsafe-wordpress-1 ls -1 wp-content/failsafe-backups/` mostra dois `.zip` e dois `.sql` de nomes base diferentes. (Hoje há dois arquivos fixos sobrescritos: o teste falha antes da mudança, que é o ponto.)
2. Cada `.zip` contém o `.sql` do próprio conjunto: `unzip -l` lista `failsafe-<base>.sql` igual ao nome base do zip.
3. O dump continua abrindo fora do WP: `unzip -p <zip> <base>.sql | head` mostra o cabeçalho `-- Failsafe Backup SQL dump`.

**Tela**
4. Existe o item de menu “Failsafe”; a página lista os conjuntos em ordem decrescente de data.
5. A data exibida está no fuso do site (Ajustes → Geral), não em UTC.
6. A coluna de tamanho bate com o disco: comparar com `docker exec failsafe-wordpress-1 stat -c %s wp-content/failsafe-backups/<arquivo>` (diferença só de arredondamento).
7. Diretório vazio → estado vazio com texto e botão “Backup agora”, nenhuma tabela, nenhum warning de PHP.
8. Editor (sem `manage_options`) em `admin.php?page=failsafe-backups` recebe “Sorry, you are not allowed to access this page.”, e o menu não aparece.

**Download**
9. “Baixar (.zip)” entrega o arquivo e o `sha256sum` do baixado é igual ao do arquivo no container.
10. Cabeçalhos corretos: `Content-Type: application/zip`, `Content-Disposition: attachment`, `Content-Length` igual ao `stat -c %s`, e `Cache-Control` sem `public`.
11. `unzip -t` no arquivo baixado diz “No errors detected”.
12. Sem nonce → tela “Are you sure you want to do this?”; nonce de outro usuário → idem.
13. `?backup=../../wp-config.php` → 400 ou 403, nunca 200; a resposta não contém `DB_PASSWORD` nem `DB_NAME`.
14. `?tipo=php` → 400; `?backup=failsafe-20260101-000000` (inexistente, formato válido) → 404.
15. Download de `.sql` devolve `application/octet-stream` e o arquivo abre como texto.

**Exclusão**
16. Excluir remove os dois arquivos, a linha desaparece após o redirect e o aviso “Backup excluído.” aparece.
17. Acesso direto por URL ao arquivo excluído devolve 403 ou 404.
18. POST de exclusão com nonce de outro backup falha (nonce por arquivo).

**Retenção**
19. Com N=2 (definir a option `failsafe_retention_count` com um one-liner PHP carregando o `wp-load.php`, já que a imagem não tem wp-cli), criar 3 backups → restam 2 conjuntos em disco e o mais recente é o recém-criado.
20. Com N=0, criar 3 backups → os 3 continuam lá.
21. Apagar à mão o `.sql` de um conjunto antigo e rodar backups até passar de N → o conjunto sem `.sql` continua listado, marcado “incompleto”, e não contou para N.
22. Backup que falha não apaga nada: `docker exec -u root failsafe-wordpress-1 chmod 555 wp-content/failsafe-backups`, clicar em “Backup agora” → aviso de erro na página e nenhum backup apagado; depois `chmod 755`.

**Código**
23. Sintaxe: `docker run --rm -v $PWD/src:/w -w /w php:8.3-cli sh -c 'find . -type f | grep php$ | xargs -n1 php -l'` sem erro.
24. `vendor/bin/phpcs --standard=WordPress src/` sem erro.
25. `grep -rn 'esc_url( *wp_nonce_url' src/` sem resultado (pegadinha 1).
26. Nenhum `date(` ou `gmdate(` usado para exibir data ao usuário (só para nome de arquivo e cabeçalho do dump, com justificativa no código).
27. Nenhuma saída de `$_GET` ou `$_SERVER` sem `esc_*` (revisão manual mais phpcs `WordPress.Security.EscapeOutput`).

---

## 12. Não-objetivos (explícitos)

- Restaurar (iteração 3). A lista não tem botão “Restaurar”.
- Agendamento, tela de configurações, e-mail, checagem de limites, escrita atômica `.part` e lock de execução (iterações 4 e 5). A listagem já ignora `.part` para não quebrar quando a 5 chegar.
- Paginação, busca, ordenação por coluna e bulk actions. A retenção mantém o conjunto pequeno; passar de ~20 é sintoma de N errado, não de falta de paginação.
- Verificação de integridade do zip pela interface (fica como teste manual, `unzip -t`).
- Mover o diretório para fora do webroot (iteração 6, decisão de arquitetura do produto).
- Destinos remotos, criptografia, multisite, WP-CLI, histórico em tabela própria.
- Traduzir o nome do arquivo: o nome base é ASCII e ordenável de propósito.

---

## 13. Riscos residuais e questões abertas

| Risco | Estado |
|---|---|
| Diretório sob `wp-content` é servível em nginx ou LiteSpeed (`.htaccess` é só Apache). No container Apache, medido: 403. | Aceito nesta iteração; mitigação real é mover para fora do webroot (iteração 6). Registrar no readme como limite honesto. |
| Nome base com timestamp é adivinhável se o diretório estiver exposto. | Aceito; alternativa barata (sufixo aleatório de 6 caracteres) fica anotada, não implementada. |
| Download de 37 MB consome banda e tempo, sem rate limit. | Aceito: só admin autenticado. |
| Zip de site grande ainda estoura memória (problema da iteração 1, tratado na 5). | Fora de escopo; a lista mostra o tamanho, o que ajuda o usuário a ver o problema. |
| Exclusão concorrente com download em andamento. | Em POSIX o handle aberto sobrevive; se `delete()` falhar, vira aviso, não corrupção. |
| Questão aberta: N padrão 5 é chute ou medida? | 5 conjuntos de ~38 MB dão ~190 MB, que cabe em hospedagem compartilhada típica. Revisar com dado real depois da iteração 5. |

---

## 14. Ordem de execução sugerida

1. `storage.php`: nome base, validação, `failsafe_fs()`, `failsafe_get_backups()`; conferir com um script temporário fora do repositório.
2. `actions.php`: nome base no dump e no zip, redirect para a página. Verificar com `ls` que existem dois conjuntos.
3. `menu.php`, `backups-page.php`, `format.php`, `partials.php`: a lista na tela, ainda sem ações.
4. `download.php`: streaming e os testes negativos 12 a 15 antes de comemorar.
5. Exclusão (form POST, nonce por arquivo), avisos e `assets/admin.js`.
6. Retenção e testes 19 a 22.
7. Widget vira resumo; rodar phpcs e o checklist completo da seção 11.

Cada passo termina com algo verificável na tela. Nenhum passo é “refatorar depois”.

---

**Anexo — verificações feitas em 2026-10-24.** Todas as URLs da seção 7 responderam 200 e as assinaturas foram lidas na página, não de memória. As medições de comportamento (`WP_Filesystem`, `dirlist`, `size_format`, `wp_nonce_url` versus `esc_url`, 403 do `.htaccess`, tamanhos dos arquivos) foram feitas no container `failsafe-wordpress-1`, WordPress 7.1.3 e PHP 8.3.36.
