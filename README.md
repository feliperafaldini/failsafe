# Failsafe Backup

Plugin de backup para WordPress. Adiciona um widget no painel que gera, em um clique, um arquivo `.zip` com os arquivos do site e um dump `.sql` do banco de dados.

Este repositório contém o plugin e o ambiente local de desenvolvimento. Apenas o conteúdo de `src/` é publicado no diretório de plugins do WordPress.org.

## Estado atual (0.1.0)

O que funciona hoje:

- Botão **Backup agora** no widget do painel, protegido por nonce e capability (`manage_options` por padrão).
- Dump de todas as tabelas do banco atual, com `DROP TABLE IF EXISTS`, `CREATE TABLE` e `INSERT` por linha.
- Snapshot consistente, `FOREIGN_KEY_CHECKS = 0`, `COMMIT` no fim e checagem de `$wpdb->last_error` a cada consulta.
- ZIP com o dump na raiz e todos os arquivos do site, exceto o diretório de backups, `wp-content/cache` e `wp-content/upgrade`.
- Diretório `wp-content/failsafe-backups/` criado na ativação e protegido por `.htaccess` e `index.php`.
- Aviso de sucesso no painel com o tamanho do dump e do arquivo ZIP.
- Desinstalação limpa: remove as options e mantém os backups, a menos que a exclusão seja habilitada explicitamente.

O que ainda **não** existe: restauração, agendamento, lista/download/exclusão de backups pela interface e suporte a multisite. A camada de armazenamento em `src/includes/storage.php` (nomes com data, listagem, exclusão e retenção) já está implementada, mas ainda não tem tela no painel.

## Requisitos

- WordPress 6.2 ou superior (testado até 7.1).
- PHP 8.0 ou superior na prática. O cabeçalho do plugin ainda declara 7.4; veja **Dívida conhecida**.
- Extensão PHP `zip` (`ZipArchive`) habilitada.
- Permissão de escrita em `wp-content/` para o usuário do servidor web.
- Usuário do banco com permissão para `SHOW TABLES`, `SHOW CREATE TABLE` e `SELECT`.

## Estrutura

- `src/` — código do plugin (é o que sobe para o diretório oficial).
  - `src/failsafe.php` — cabeçalho, constantes e mapa de `require_once`.
  - `src/admin/setup.php` — cria e protege o diretório de backups.
  - `src/admin/dashboard.php` — widget do painel, formulário e aviso de resultado.
  - `src/admin/actions.php` — handler do `admin_post_*` e as funções de dump e ZIP.
  - `src/includes/storage.php` — camada de armazenamento (nomes, listagem, exclusão e retenção), ainda sem interface.
  - `src/uninstall.php` — limpeza de options e, opcionalmente, dos backups.
  - `src/readme.txt` — texto do diretório do WordPress.org.
- `tests/` — testes de fumaça e round-trip de importação. Ficam fora de `src/` para não irem no pacote do plugin.
- `stubs/` — stubs do WordPress para análise estática. Não fazem parte do pacote.
- `docker-compose.yml` — ambiente local; não faz parte do plugin publicado.
- `CHANGELOG.md` — histórico de versões.
- `AGENTS.md` — convenções do projeto.

## Rodar localmente

```sh
docker compose up -d      # site em http://localhost:8080
docker compose down       # para os containers
```

Os containers são `failsafe-wordpress-1` e `failsafe-db-1`. As credenciais locais ficam apenas em `docker-compose.yml`.

## Testes

```sh
./tests/run-tests.sh
```

O script exige a stack do Docker rodando e executa:

1. `php -l` nos arquivos do plugin.
2. Testes unitários dentro do container WordPress (`tests/test-dump.php` e `tests/test-zip.php`).
3. Round-trip: copia o dump gerado, importa num banco `teste_ci` descartável, importa de novo para provar que o `DROP TABLE` funciona e compara a contagem de linhas de `wp_posts`, `wp_options` e `wp_users`.

O banco de teste é criado e destruído pelo próprio script. Nunca importe um dump num banco real.

## Limitações conhecidas

- **Tamanho do banco**: o maior banco testado tem cerca de 2 MB e 12 tabelas. O dump é montado em memória, então bancos maiores não foram validados e podem estourar o `memory_limit` (128 MB no ambiente de teste).
- **Timeout**: o backup roda na requisição do admin. No ambiente local leva cerca de 9 segundos; sob Apache o limite padrão é 30 segundos. Sites grandes precisam de execução em background (ainda não implementada).
- **nginx**: a proteção do diretório depende de `.htaccess`. Em nginx é preciso uma regra própria no servidor ou definir `FAILSAFE_BACKUP_DIR` para um diretório fora do webroot.
- **Multisite**: não suportado.
- **Objetos de banco**: views, triggers, procedures e events não entram no dump.
- **Somente o backup mais recente**: os arquivos têm nomes fixos e são sobrescritos a cada execução.

## Dívida conhecida

- O código usa `str_starts_with()` (PHP 8.0), mas o cabeçalho do plugin e o `readme.txt` declaram PHP 7.4. Antes de publicar, ou o cabeçalho sobe para 8.0, ou entra um fallback para 7.4.
- A restauração, o agendamento e a lista de backups ainda não têm interface.
- Não há `readme.txt` traduzido; o texto oficial é em inglês, como o diretório exige.
- O pacote do plugin ainda não tem script de build nem `.distignore`; hoje o pacote é o conteúdo de `src/`.

## Roadmap

Restauração, agendamento e gerenciamento de backups (listar, baixar e excluir) estão em desenvolvimento. Não há versão nem data prometida.

## Licença

GPLv2 ou superior. Veja https://www.gnu.org/licenses/gpl-2.0.html.
