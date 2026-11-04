# Mapa do código

Onde cada parte do plugin mora. Só `src/` entra no pacote publicado.

## Plugin (`src/`)

- `src/` — código do plugin (é o que sobe para o diretório oficial).
  - `src/failsafe.php` — cabeçalho, constantes (`FAILSAFE_VERSION`, `FAILSAFE_BACKUP_DIR`, `FAILSAFE_RETENTION_DEFAULT`) e mapa de `require_once`.
  - `src/includes/storage.php` — nomes, caminhos, listagem, exclusão, retenção e escrita atômica.
  - `src/includes/limits.php` — estimativas de banco e de arquivos do site, e checagens de memória, disco e tempo.
  - `src/includes/dump.php` — escritor do dump em streaming.
  - `src/includes/notifications.php` — montagem e envio do e-mail de status.
  - `src/includes/restore.php` — parser incremental de SQL, allowlist, execução do dump, extração validada do ZIP e limpeza do diretório temporário.
  - `src/includes/cron.php` — evento agendado, frequências, lock, execução e registro do último resultado.
  - `src/admin/setup.php` — cria e protege o diretório de backups.
  - `src/admin/menu.php` — menu **Failsafe**, hook da página e carregamento do JS.
  - `src/admin/actions.php` — handler do backup manual e as funções de caminho, ZIP e orquestração.
  - `src/admin/backups-page.php` — página **Backups**, tabela, estado vazio e avisos.
  - `src/admin/download.php` — download do `.zip` e do `.sql` por `admin-post.php`.
  - `src/admin/delete.php` — exclusão em POST com nonce por conjunto.
  - `src/admin/dashboard.php` — widget do painel.
  - `src/admin/restore.php` — tela e handler de **Ferramentas > Failsafe Restore**.
  - `src/admin/settings.php` — tela de configurações, opções, sanitização e status do agendamento.
  - `src/admin/format.php` — formatação de data e tamanho.
  - `src/admin/partials.php` — botão de backup e ações da tabela.
  - `src/uninstall.php` — limpeza das opções e, com opt-in, dos backups.
  - `src/assets/admin.js` — confirmação das ações destrutivas marcadas com `data-confirm`.
  - `src/languages/failsafe-backup.pot` — template de tradução.
  - `src/readme.txt` — texto do diretório do WordPress.org.
## Repositório

- `bin/build.sh` — gera os dois pacotes a partir de `src/`.
- `tests/` — lint, testes unitários, streaming, cron, configurações, round-trip e protocolo do desastre. Ficam fora de `src/` para não irem no pacote do plugin.
- `stubs/` — stubs do WordPress para análise estática. Não fazem parte do pacote.
- `docker-compose.yml` — ambiente local; não faz parte do plugin publicado.
- `.distignore` — arquivos que não devem entrar no pacote.
- `CHANGELOG.md` / `CHANGELOG.pt-BR.md` — histórico de versões em inglês e português.
- `MATURITY.md` / `SUBMISSION.md` — avaliação de maturidade e checklist de publicação.
- `AGENTS.md` — convenções do projeto.

