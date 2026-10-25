# Convenções (leia AGENTS.md do repositório antes de tudo)

- Código em **inglês**: nomes de variáveis, funções, constantes e comentários.
- Strings de interface em **inglês** dentro de `__( '...', 'failsafe' )` / `esc_html__()` — o plugin vai para o diretório do WordPress.org e será traduzido.
- WPCS: tabs, aspas simples sem interpolação, espaços dentro dos parênteses, Yoda conditions, docblock em toda função.
- Prefixo `failsafe_` em funções globais; `FAILSAFE_*` em constantes.
- Toda função que pode falhar devolve `WP_Error`; nada de `exit` silencioso.
- Saída sempre escapada; entrada por nãoce + capability.
- NÃO use `mysqldump` (não existe na imagem). NÃO adicione dependências externas (sem Composer, sem bibliotecas).
- PHP 8, WordPress 6.2+.

# Regras do worktree

- Você está num git worktree isolado. **Não** rode `git commit`, `git push` nem `git checkout`.
- **Não** altere arquivos fora da sua lista de propriedade — outro agente trabalha neles em paralelo.
- Ao terminar, rode `php -l` em cada arquivo que criou/alterou e escreva `REPORT.md` na raiz do worktree com: arquivos, decisões, o que não deu para verificar e como o agente principal deve integrar.

# Tarefa: Iteração 3 — restauração em 1 clique (implementação B — abordagem independente)

Objetivo: restaurar o site a partir de um backup já existente no diretório protegido, com segurança.

## Arquivos que você PODE criar/alterar
- `src/includes/restore.php` (novo) — toda a lógica.
- `src/admin/restore.php` (novo) — handler `admin_post_failsafe_restore_backup` e render da tela/etapa.
- `tests/test-restore.php` (novo) — testes de unidade do parser SQL (sem banco).

NÃO altere `actions.php`, `dashboard.php`, `storage.php` nem `failsafe.php`.

## Requisitos
1. **Parser SQL**: função `failsafe_split_sql_statements( string $sql ): array` que divide um dump em comandos respeitando aspas simples/duplas, backslash, comentários `--`/`#`/`/* */` e `DELIMITER` (tratar `;` dentro de string como conteúdo). Tem que sobreviver ao dump gerado por `failsafe_build_sql_dump()`.
2. **Execução**: `failsafe_restore_database( string $sql ): true|WP_Error` executa os comandos em ordem com `$wpdb->query()`, ignora `SET`/comentários quando fizer sentido, aborta com `WP_Error` no primeiro erro citando o comando (truncado).
3. **Extração segura**: abrir o zip com `ZipArchive`, validar cada entrada (sem `../`, sem caminho absoluto, sem symlink), extrair para um diretório temporário fora do webroot (`get_temp_dir()`), limite de entradas.
4. **Segurança**: capability `failsafe_backup_capability()`, nonce, confirmação explícita (campo `confirm` com o nome base digitado ou checkbox), e **backup de segurança automático antes de restaurar** (chamar `failsafe_create_backup()` se existir).
5. **Pós-restore**: mensagem clara de sucesso/falha, nada de `exit` mudo.
6. Não apagar o backup usado.

## Liberdade de abordagem
Você pode escolher uma arquitetura diferente da óbvia (por exemplo: importar tabela a tabela com transação, ou usar `$wpdb->query` em lote por tabela). Documente a escolha e o porquê no REPORT.md.

## Critério de pronto
- `php -l` limpo.
- `tests/test-restore.php` cobre: `;` dentro de string, `--` comentário, `/* */`, dump com `SET NAMES`, string com `'`, e caso vazio.
- REPORT.md com o passo a passo de integração no `failsafe.php` (linha exata do require).
