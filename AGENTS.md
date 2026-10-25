# Failsafe Backup — regras do projeto

## Idioma

- Comunicação com o dono do projeto e documentação interna em **português**.
- Código em **inglês**: nomes de variáveis, funções, constantes e comentários.
- Strings de interface do plugin em **inglês**, dentro de `__( '...', 'failsafe' )` — o plugin vai para o diretório do WordPress.org e será traduzido.

## Padrões de código

- Prefixo `failsafe_` em funções globais; constantes `FAILSAFE_*`.
- WordPress Coding Standards: tabs, aspas simples sem interpolação, espaços ao redor de parênteses, Yoda conditions.
- SQL: palavras-chave em maiúsculas; identificador dinâmico via `$wpdb->prepare( '%i', ... )`; valor de texto via `esc_sql()` + aspas escritas à mão; valor binário/não-UTF-8 como literal hexadecimal `X'...'`.
- Saída sempre escapada; entrada por nonce + capability (`failsafe_backup_capability()`, filtrável).
- Toda função que pode falhar devolve `WP_Error` em vez de `exit`/`false` silencioso.
- Mensagens de erro sempre em `__()` com o text domain `failsafe`.

## Arquitetura

- `src/failsafe.php` — apenas header, constantes e o mapa de `require_once`.
- `src/admin/setup.php` — cria e protege o diretório de backups (ativação + `admin_init`).
- `src/admin/dashboard.php` — widget do painel, formulário e aviso de resultado.
- `src/admin/actions.php` — handler do `admin_post_*` e as funções de trabalho (`failsafe_create_backup`, `failsafe_build_sql_dump`, `failsafe_sql_value`, `failsafe_create_zip`, `failsafe_path_is_excluded`, `failsafe_log`).
- `src/uninstall.php` — limpa options; só apaga os backups se `failsafe_delete_backups_on_uninstall` estiver ligado.
- Regra: o handler só valida, chama e redireciona; a lógica fica em funções testáveis.

## Contrato do dump

- Cabeçalho com site, data, `SET SESSION sql_mode = ''`, `SET NAMES <charset> COLLATE <collate>`.
- `SET FOREIGN_KEY_CHECKS = 0` + `START TRANSACTION WITH CONSISTENT SNAPSHOT` no início; `COMMIT` e `FOREIGN_KEY_CHECKS = 1` no fim.
- `DROP TABLE IF EXISTS` antes de cada `CREATE TABLE`: restaurar sobre banco existente precisa funcionar.
- `$wpdb->last_error` é checado depois de cada consulta: tabela faltando nunca pode virar "backup criado".

## Testes

- `./tests/run-tests.sh` — lint, testes unitários (via `wp-load` no container), round-trip de importação e reimportação (prova o `DROP TABLE`).
- Os testes vivem fora de `src/` para não irem no pacote do plugin.
- Nunca importar dump num banco real: sempre um banco `teste_*` criado e destruído pelo script.

## Ambiente local

- `docker compose up -d`; site em http://localhost:8080.
- Containers: `failsafe-wordpress-1`, `failsafe-db-1`.
- Credenciais apenas no `docker-compose.yml`; o script de teste as lê de lá. Nunca em código, teste ou log.
- `debug.log` em `/var/www/html/wp-content/debug.log` é o primeiro lugar a olhar quando algo falha.

## Limites conhecidos (dívida registrada, não bug)

- **Memória**: o dump é montado em memória (`memory_limit` 128M no container). Banco acima de ~50 MB pode estourar; a correção é gravar em blocos, planejada para a iteração de escala.
- **Timeout**: sob Apache o limite é 30 s; o backup local leva ~9 s. Sites grandes precisam de execução em background (WP-Cron + lock).
- **nginx**: a proteção do diretório depende de `.htaccess`; em nginx é preciso regra no servidor ou mover o diretório para fora do webroot.
- **Multisite**: `SHOW TABLES` inclui todas as tabelas da rede num dump único.
- **Views, triggers, procedures, events** não entram no dump.
- **Sem `readme.txt`**: necessário antes da submissão ao diretório do WordPress.org.

## Modo de trabalho

- O agente implementa, testa e audita; o usuário revisa e autoriza commits.
- Commits só com autorização explícita, em inglês, no formato `tipo: resumo`.
