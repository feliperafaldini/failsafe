# Iteração 3a — restauração em 1 clique (implementação A)

## Arquivos

Todos novos; nenhum arquivo fora da lista de propriedade foi alterado.

- `src/includes/restore.php` — parser SQL, execução no banco, backup de segurança,
  extração segura do ZIP e limpeza do diretório temporário.
- `src/admin/restore.php` — handler `admin_post_failsafe_restore_backup`, tela em
  Ferramentas → Failsafe Restore, formulário por backup e aviso pós-restore.
- `tests/test-restore.php` — 68 verificações, sem tocar no banco: parser, formato do
  dump, `DELIMITER`, validação de nomes de entrada e extração (inclusive ZIP malicioso).

## Decisões

### Parser (`failsafe_split_sql_statements()`)

- Retorna os comandos **sem** o delimitador final; `$wpdb->query()` não precisa dele.
- Estados explícitos para aspas simples, duplas e backticks; `\` escapa o próximo
  caractere dentro de strings, aspas duplicadas (`''`, `""`, ` `` `) continuam a string.
- Comentários de linha `-- ` (só quando há espaço depois dos dois hífens, como no MySQL),
  `#`, e blocos `/* */`. Comentários não entram nos comandos.
- Comentários de versão (`/*!40101 SET ... */`) são tratados como SQL executável, com o
  número de versão removido — assim o parser também aceita dumps do `mysqldump`, apesar
  de o dump do plugin não gerar esse formato.
- `DELIMITER xx` só é reconhecido no início de um comando (buffer sem conteúdo), troca o
  delimitador (inclusive multi-caractere) e não gera comando. `DELIMITER ;` restaura o
  padrão. Isso permite procedimentos com `;` no corpo.
- Último comando sem delimitador também é devolvido.

### Execução (`failsafe_restore_database()`)

- Executa em ordem com `$wpdb->query()`, limpando `$wpdb->last_error` antes de cada um.
- Aborta no primeiro erro de dados com `WP_Error` (`failsafe_restore_query_failed`) que
  informa posição, total, mensagem do banco e o comando truncado em 200 caracteres.
- Falha de `SET` é registrada no log e ignorada: usuários de banco restritos podem não ter
  permissão para alguns `SET` de sessão do dump, e isso não deve impedir a restauração.
  `SET FOREIGN_KEY_CHECKS`/`SET NAMES` continuam sendo executados quando permitidos.
- `suppress_errors()` é ligado durante a execução para não imprimir erro do MySQL na tela,
  e restaurado em `finally` (inclusive nos retornos de erro).
- A restauração **não é atômica**: DDL no MySQL faz commit implícito. O backup de
  segurança é a rede de proteção; isso está dito na tela e no log.

### Extração segura

- `ZipArchive::CHECKCONS`, limite de entradas (`FAILSAFE_RESTORE_MAX_ENTRIES` = 50000,
  filtrável por `failsafe_restore_max_entries`).
- **Valida todas as entradas antes de escrever qualquer byte**: rejeita nome vazio, byte
  nulo, caminho absoluto (`/`, `C:`), qualquer segmento `..` (com `/` ou `\`) e symlink
  Unix (`getExternalAttributesIndex` + `S_IFLNK`).
- Diretório temporário criado em `get_temp_dir()` com nome aleatório e permissão `0700`;
  recusa extrair se o diretório estiver dentro de `ABSPATH`.
- Extrai todas as entradas validadas para o temporário e devolve o caminho real.
- `failsafe_remove_temp_directory()` só apaga dentro de `get_temp_dir()` e é usada em
  todos os caminhos de erro; a limpeza também é feita depois do restore bem-sucedido.

### Fluxo do handler

1. Capability `failsafe_backup_capability()` → `wp_die` com mensagem se negado.
2. `check_admin_referer( 'failsafe_restore_backup', 'failsafe_restore_nonce' )`.
3. Nome base validado por `failsafe_is_valid_backup_name()`; confirmação exige digitar o
   nome base exato no campo `confirm`.
4. Backup de segurança automático: chama `failsafe_create_backup()` via
   `failsafe_create_restore_safety_backup()`. **Se o backup de segurança falhar, o restore
   é abortado** — nunca sobrescrever o banco sem fallback.
5. Extrai o ZIP, lê `failsafe-backup.sql`, restaura, limpa o temporário e chama
   `wp_cache_flush()` (também no erro, pois pode ter havido restore parcial).
6. Sucesso/falha vão para um transient por usuário (5 minutos) e redirecionam para a tela;
   nada de mensagem sensível na URL e nada de `exit` mudo.
7. O backup usado nunca é apagado; a retenção também não é chamada.

### Escopo

- **Somente banco**: os arquivos do site são extraídos para validação, mas não são copiados
  sobre o webroot (evita sobrescrever o site em produção a partir de uma requisição admin).
  A tela avisa que os arquivos não são alterados.
- Tela própria em Ferramentas → Failsafe Restore (`tools.php?page=failsafe-restore`), para o
  recurso funcionar sem editar `dashboard.php`. O formulário também pode ser reaproveitado
  no widget chamando `failsafe_render_restore_form( $backup['base'] )`.
- Strings de interface em inglês, como pede o TASK.md (sobrepõe o AGENTS.md).

## Integração no `failsafe.php`

Hoje o arquivo tem 35 linhas:

```
32: require_once FAILSAFE_DIR . '/includes/storage.php';
33: require_once FAILSAFE_DIR . '/admin/setup.php';
34: require_once FAILSAFE_DIR . '/admin/actions.php';
35: require_once FAILSAFE_DIR . '/admin/dashboard.php';
```

Inserir **uma linha depois da linha 32** e **uma linha no fim**:

```diff
 require_once FAILSAFE_DIR . '/includes/storage.php';
+require_once FAILSAFE_DIR . '/includes/restore.php';
 require_once FAILSAFE_DIR . '/admin/setup.php';
 require_once FAILSAFE_DIR . '/admin/actions.php';
 require_once FAILSAFE_DIR . '/admin/dashboard.php';
+require_once FAILSAFE_DIR . '/admin/restore.php';
```

Resultado esperado (linhas 32–37):

```
32: require_once FAILSAFE_DIR . '/includes/storage.php';
33: require_once FAILSAFE_DIR . '/includes/restore.php';
34: require_once FAILSAFE_DIR . '/admin/setup.php';
35: require_once FAILSAFE_DIR . '/admin/actions.php';
36: require_once FAILSAFE_DIR . '/admin/dashboard.php';
37: require_once FAILSAFE_DIR . '/admin/restore.php';
```

A ordem importa apenas em runtime: `includes/restore.php` precisa ser carregado antes de
`admin/restore.php` por causa da constante `FAILSAFE_RESTORE_DUMP_NAME` usada no handler.
As funções de `storage.php`/`actions.php` são chamadas em tempo de hook, então a ordem
entre elas não afeta o carregamento.

### `tests/run-tests.sh` (não alterado, conforme a lista de propriedade)

Duas adições recomendadas:

1. No lint, incluir `includes` (hoje o loop só cobre `failsafe.php` e `admin/*.php`):

```bash
for file in "$SRC"/failsafe.php "$SRC"/includes/*.php "$SRC"/admin/*.php; do
```

2. Depois da linha 45 (`test-zip.php`), rodar o teste novo:

```bash
docker exec -u www-data "$WP" php "$TESTS_REMOTE/test-restore.php" || FAILED=1
```

## Verificação executada

- `php -l` limpo nos três arquivos.
- `tests/test-restore.php`: **68 checks, 0 failures** (rodado no container com `wp-load`,
  usando uma cópia temporária do teste apontando para o arquivo deste worktree; o teste
  versionado aponta para o caminho normal do plugin depois da integração).
  Cobre: `;` em string simples/dupla/backtick, `--`, `#`, `/* */`, comentário de versão,
  BOM UTF-8, aspas escapadas com `\` e duplicadas, dump completo com `SET NAMES`,
  `DELIMITER`, comando final sem `;`, `--` sem espaço, nomes de entrada maliciosos e ZIP
  com `../` e symlink.
- **Teste de integração fora do repositório** (script descartável, banco `teste_*` criado e
  destruído): dump real gerado por `failsafe_build_sql_dump()` restaurado com
  `failsafe_restore_database()`; contagens de `wp_posts` (8), `wp_options` (158),
  `wp_users` (1) e `wp_postmeta` (4) iguais; segunda restauração sobre o banco existente
  passou (prova do `DROP TABLE`); comando inválido abortou com
  `failsafe_restore_query_failed` citando o comando e a posição; dump vazio rejeitado.
- **ZIP real do diretório protegido** (37 MB, >1000 entradas): extração validada em ~1,09 s,
  `failsafe-backup.sql` (1,3 MB) presente, limpeza do temporário confirmada.
- Parser no dump real: 231 comandos (12 `CREATE`, 12 `DROP`, 201 `INSERT`, 4 `SET`,
  `START`, `COMMIT`).
- `SET` sem privilégio (`SET @@GLOBAL.max_connections`) ignorado e o comando seguinte
  executado; dump só com esse `SET` devolve `true`.
- Handler em CLI com usuário admin e nonce real: confirmação errada grava o transient de
  erro (`The confirmation text does not match...`) e não chega a tocar no banco.
- Aviso pós-restore renderiza com o tipo gravado no transient e escapa HTML
  (`<script>` virou `&lt;script&gt;`).
- Tela e formulário renderizam sem erro; o form contém nonce, campo `confirm` e o nome base.

## O que não deu para verificar

- O restore completo **via HTTP** (capability → nonce → backup de segurança → extração →
  restore → redirect → notice) não foi disparado, porque isso sobrescreveria o banco de
  desenvolvimento compartilhado. As partes foram exercitadas isoladamente, como acima; o
  handler é fino de propósito.
- Capability negada não foi testada em requisição real (o código segue o mesmo padrão de
  `actions.php`).
- O backup de segurança automático não foi executado de ponta a ponta para não sobrescrever
  `failsafe-backup.zip`/`.sql` de nome fixo que os outros worktrees usam no container
  compartilhado. A chamada é direta a `failsafe_create_backup()`, já coberto por
  `test-zip.php`.
- Tempo total do handler sob Apache (30 s): backup (~9 s) + extração (~1 s) + restore; sites
  grandes continuam dependendo da dívida de execução em background.
- Restauração de arquivos do site ficou fora do escopo desta implementação.
- Não é possível desfazer tabelas que existem no banco atual mas não estão no dump
  (o contrato do dump só faz `DROP` das tabelas que ele contém). Multisite idem.
- Um ZIP gerado em instalação com diretórios simbólicos (ex.: plugin apontando para fora de
  `wp-content`) será rejeitado pela regra "sem symlink"; é o comportamento pedido.

## Observações para o agente principal

- Iteração 1 grava o backup em nomes fixos (`failsafe-backup.zip/.sql`), então o backup de
  segurança criado antes do restore **não aparece** na listagem de `storage.php` (que espera
  nomes com timestamp). Se a iteração 2 já mudou `failsafe_create_backup()` para nomes
  datados, isso se resolve sozinho; se não, vale alinhar depois.
- Não rodei `git commit`/`push`/`checkout`. `TASK.md` já estava não rastreado antes do
  trabalho.
- Se o main agent optar pela implementação B, não traga estes três arquivos junto: os nomes
  de função (`failsafe_split_sql_statements`, `failsafe_restore_database`, etc.) colidem.
