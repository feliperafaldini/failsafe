# Failsafe Backup

Plugin de backup para WordPress. Cria um conjunto datado com um dump `.sql` do banco e um `.zip` com o dump e os arquivos do site, lista e gerencia os backups no painel, restaura o banco a partir de um ZIP e pode rodar em um agendamento.

Este repositório contém o plugin e o ambiente local de desenvolvimento. Apenas o conteúdo de `src/` é publicado no diretório de plugins do WordPress.org.

## Estado atual (0.2.0)

O que funciona hoje:

- Backup manual pelo botão **Backup agora**, no widget do painel ou na página **Failsafe**, protegido por nonce e capability (`manage_options` por padrão, filtrável por `failsafe_backup_capability`).
- Conjuntos datados no formato `failsafe-YYYYMMDD-HHMMSS`, com `.sql` e `.zip`; um backup novo não sobrescreve o anterior. Colisões no mesmo segundo recebem sufixo `-2` a `-9`.
- Dump de todas as tabelas do banco atual, com `DROP TABLE IF EXISTS`, `CREATE TABLE` e `INSERT` por linha, snapshot consistente, `FOREIGN_KEY_CHECKS = 0`, `COMMIT` no fim e checagem de `$wpdb->last_error` a cada consulta.
- ZIP com o dump na raiz e todos os arquivos do site, exceto o diretório de backups, `wp-content/cache` e `wp-content/upgrade`.
- Dump em streaming, com memória constante; escrita atômica via `.part` e `WP_Filesystem`; ZIP parcial é apagado para não aparecer como backup válido.
- Página **Failsafe** com tabela de data, arquivos, tamanho e ações, incluindo download do `.zip` e do `.sql` por `admin-post.php` com nonce e exclusão em POST com nonce por conjunto.
- Retenção de backups completos, com padrão 5, faixa de 0 a 50 e 0 para manter todos. Conjuntos incompletos ficam até a exclusão manual.
- Widget do painel com o último backup, a quantidade de conjuntos, o tamanho total em disco e o link para a lista.
- Restauração do banco em **Ferramentas > Failsafe Restore**, com confirmação digitada do nome do backup, backup de segurança completo antes da restauração, aviso de que os arquivos do site não são alterados e sem que a rotina de restauração apague o backup usado.
- Agendamento com as frequências horária, duas vezes ao dia, diária e semanal, lock que impede duas execuções simultâneas (agendadas ou manuais) e retomada de lock abandonado.
- Tela **Configurações > Failsafe Backup** com o estado do agendamento (última execução, resultado, próxima execução), frequência e retenção.
- Checagem prévia de memória, espaço em disco e tempo de execução antes de iniciar o dump.
- E-mail de status para backups manuais: falha quando o endereço do administrador é válido, sucesso apenas com a opção `failsafe_email_on_success` ligada.
- Diretório `wp-content/failsafe-backups/` criado na ativação e protegido por `.htaccess` e `index.php`.
- Desinstalação que remove a opção de retenção; os backups só são apagados com `failsafe_delete_backups_on_uninstall` ligado.

O que ainda **não** existe: suporte a multisite, restauração dos arquivos do site pela interface, processamento em background para sites grandes e inclusão de views, triggers, procedures e events no dump. Também não há campos nas telas para `failsafe_email_on_success` e `failsafe_delete_backups_on_uninstall`; as duas opções precisam ser definidas por código ou pela tabela de options.

## Requisitos

- WordPress 6.2 ou superior (testado até 7.1).
- PHP 8.0 ou superior, como declaram o cabeçalho do plugin e o `readme.txt`.
- Extensão PHP `zip` (`ZipArchive`) habilitada.
- Permissão de escrita em `wp-content/` para o usuário do servidor web.
- Para backup, usuário do banco com permissão para `SHOW TABLES`, `SHOW CREATE TABLE`, `SELECT` e leitura de `information_schema`.
- Para restauração, permissão para `DROP TABLE`, `CREATE TABLE` e `INSERT`, já que o dump recria as tabelas.
- WP-Cron com tráfego no site ou um cron do sistema chamando `wp-cron.php` para os backups agendados rodarem no horário.
- Instalação single-site; multisite não é suportado.

## Estrutura

- `src/` — código do plugin (é o que sobe para o diretório oficial).
  - `src/failsafe.php` — cabeçalho, constantes (`FAILSAFE_VERSION`, `FAILSAFE_BACKUP_DIR`, `FAILSAFE_RETENTION_DEFAULT`) e mapa de `require_once`.
  - `src/includes/storage.php` — nomes, caminhos, listagem, exclusão, retenção e escrita atômica.
  - `src/includes/limits.php` — estimativas de banco e de arquivos do site, e checagens de memória, disco e tempo.
  - `src/includes/dump.php` — escritor do dump em streaming.
  - `src/includes/notifications.php` — montagem e envio do e-mail de status.
  - `src/includes/restore.php` — parser de SQL, execução do dump, extração validada do ZIP e limpeza do diretório temporário.
  - `src/includes/cron.php` — evento agendado, frequências, lock, execução e registro do último resultado.
  - `src/admin/setup.php` — cria e protege o diretório de backups.
  - `src/admin/menu.php` — menu **Failsafe**, hook da página e carregamento do JS.
  - `src/admin/actions.php` — handler do backup manual e as funções de dump, ZIP e retenção.
  - `src/admin/backups-page.php` — página **Backups**, tabela, estado vazio e avisos.
  - `src/admin/download.php` — download do `.zip` e do `.sql` por `admin-post.php`.
  - `src/admin/delete.php` — exclusão em POST com nonce por conjunto.
  - `src/admin/dashboard.php` — widget do painel.
  - `src/admin/restore.php` — tela e handler de **Ferramentas > Failsafe Restore**.
  - `src/admin/settings.php` — tela de configurações, opções, sanitização e status do agendamento.
  - `src/admin/format.php` — formatação de data e tamanho.
  - `src/admin/partials.php` — botão de backup e ações da tabela.
  - `src/uninstall.php` — limpeza da opção de retenção e, opcionalmente, dos backups.
  - `src/readme.txt` — texto do diretório do WordPress.org.
- `src/assets/admin.js` — confirmação das ações destrutivas marcadas com `data-confirm`.
- `bin/build.sh` — gera `dist/failsafe.zip` a partir de `src/`; não empacota `assets/`.
- `tests/` — testes de fumaça, unitários e round-trip de importação. Ficam fora de `src/` para não irem no pacote do plugin.
- `stubs/` — stubs do WordPress para análise estática. Não fazem parte do pacote.
- `docker-compose.yml` — ambiente local; não faz parte do plugin publicado.
- `.distignore` — arquivos que não devem entrar no pacote.
- `CHANGELOG.md` — histórico de versões.
- `AGENTS.md` — convenções do projeto.

## Rodar localmente

```sh
docker compose up -d      # site em http://localhost:8080
docker compose down       # para os containers
```

Os containers são `failsafe-wordpress-1` e `failsafe-db-1`. As credenciais locais ficam apenas em `docker-compose.yml`.

## Build

```sh
./bin/build.sh
```

O script empacota `src/` em dois arquivos: `dist/failsafe.zip` (arquivos na raiz, para o `trunk/` do SVN) e `dist/failsafe-<versão>.zip` (tudo dentro de `failsafe/`, para o instalador de plugins). `src/assets/admin.js` entra nos dois.

## Testes

```sh
./tests/run-tests.sh
```

O script exige a stack do Docker rodando e executa:

1. `php -l` em `src/failsafe.php` e `src/admin/*.php`.
2. Testes unitários dentro do container WordPress: `tests/test-dump.php`, `tests/test-zip.php`, `tests/test-storage.php`, `tests/test-limits.php` e `tests/test-restore.php`.
3. Round-trip: copia o dump gerado, importa num banco `teste_ci` descartável, importa de novo para provar que o `DROP TABLE` funciona e compara a contagem de linhas de `wp_posts`, `wp_options` e `wp_users`.

O banco de teste é criado e destruído pelo próprio script. Nunca importe um dump num banco real.

## Limitações conhecidas

- **Tamanho do banco**: o maior banco testado tem cerca de 2 MB e 12 tabelas. O dump é montado em memória, então bancos maiores não foram validados e podem estourar o `memory_limit` (128 MB no ambiente de teste). A checagem prévia interrompe o caso previsível, mas um banco na casa das dezenas de megabytes provavelmente falha.
- **Timeout**: o backup roda na requisição do admin ou do WP-Cron. No ambiente local leva cerca de 9 segundos; sob Apache o limite padrão é 30 segundos. Sites grandes precisam de execução em background, que ainda não existe.
- **WP-Cron depende de tráfego**: o backup agendado só começa quando o site recebe uma requisição ou quando `wp-cron.php` é chamado por um cron do sistema. Site sem visitas atrasa o agendamento.
- **nginx**: a proteção do diretório depende de `.htaccess`. Em nginx é preciso uma regra própria no servidor ou definir `FAILSAFE_BACKUP_DIR` para um diretório fora do webroot.
- **Multisite**: não suportado. Num multisite o dump inclui todas as tabelas da rede e todos os arquivos da instalação, sem separação por site.
- **Restauração**: cobre apenas o banco; os arquivos do site não são restaurados. Uma falha no meio do dump pode deixar o banco parcialmente restaurado. Só conjuntos com ZIP não vazio podem ser restaurados pela tela, e o diretório temporário precisa ficar fora do webroot.
- **Retenção durante a restauração**: a rotina não apaga o backup usado, mas o backup de segurança também passa pela retenção; um conjunto fora do limite pode ser removido nesse momento.
- **Objetos de banco**: views, triggers, procedures e events não entram no dump.
- **Retenção**: só remove conjuntos completos; um conjunto com um arquivo faltando fica até a exclusão manual.
- **Lock**: uma segunda execução durante um backup em andamento é recusada com aviso, sem fila; é preciso tentar de novo depois.
- **E-mail**: o e-mail de status é enviado pelo backup manual. Uma falha agendada fica registrada no status e no log, mas não gera e-mail.
- **Backups da 0.1.0**: os nomes fixos `failsafe-backup.sql` e `failsafe-backup.zip` não são listados nem removidos pela retenção.
- **Dados sensíveis**: o ZIP contém `wp-config.php` e o dump do banco, com hashes de senha e dados pessoais. Trate o arquivo como confidencial.

## Dívida conhecida

- O `uninstall.php` remove todas as opções do plugin, o transient do lock e, quando o opt-in está ligado, o diretório de backups.
- `failsafe_email_on_success` e `failsafe_delete_backups_on_uninstall` não têm campo nas telas; precisam ser definidas por código ou pela tabela de options.
- A restauração não tem limite de tamanho descompactado, apenas de número de entradas (50.000) e validação de caminhos.
- `tests/run-tests.sh` faz lint de `src/failsafe.php` e `src/admin/*.php`, mas não dos arquivos em `src/includes/`.
- O texto oficial é em inglês, como o diretório exige; existe `src/languages/failsafe.pot`, mas nenhuma tradução publicada.

## Roadmap

Restauração dos arquivos do site, suporte a multisite, processamento em background para sites grandes e dump de views, triggers, procedures e events estão planejados. Não há versão nem data prometida.

## Licença

GPLv2 ou superior. Veja https://www.gnu.org/licenses/gpl-2.0.html.
