# Changelog

Todas as mudanças relevantes deste plugin são registradas neste arquivo.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto usa versionamento semântico. O `readme.txt` em `src/` repete o histórico em inglês para o diretório do WordPress.org.

## [Não publicado]

### Em desenvolvimento

- Restauração de backups pela interface.
- Agendamento de backups.
- Tela para listar, baixar e excluir backups.
- Suporte a multisite.

Nada disso tem versão ou data definida. A camada de armazenamento (`src/includes/storage.php`) já existe, mas ainda não está ligada ao painel.

## [0.1.0] - 2026-10-24

### Adicionado

- Widget **Failsafe Backup** no painel com o botão **Backup agora**.
- Handler `admin_post_failsafe_run_backup` com nonce e checagem de capability (`manage_options` por padrão, filtrável por `failsafe_backup_capability`).
- Dump SQL de todas as tabelas do banco atual, com cabeçalho de site e data, `SET SESSION sql_mode = ''`, `SET NAMES` com charset e collation do banco, snapshot consistente, `DROP TABLE IF EXISTS`, `CREATE TABLE` e um `INSERT` por linha.
- Checagem de `$wpdb->last_error` depois de cada consulta; erro vira `WP_Error` e interrompe o backup em vez de gerar arquivo parcial.
- Literais hexadecimais para valores binários ou fora de UTF-8.
- ZIP com o dump na raiz e todos os arquivos legíveis do site, exceto o diretório de backups, `wp-content/cache` e `wp-content/upgrade`.
- Criação e proteção de `wp-content/failsafe-backups/` na ativação e a cada acesso ao admin, com `.htaccess` e `index.php`.
- Aviso de sucesso no painel com o tamanho do dump e do ZIP.
- Log condicional em `wp-content/debug.log` quando `WP_DEBUG` está ligado.
- Desinstalação limpa: remove `failsafe_retention_count` e, quando habilitado, `failsafe_delete_backups_on_uninstall`; os backups só são apagados com a opção de exclusão ligada.
- Camada de armazenamento com nomes datados, listagem, exclusão e retenção (ainda sem interface).
- Suíte de testes com lint, testes unitários no container e round-trip de importação e reimportação do dump.

### Segurança

- Formulário protegido por nonce e capability.
- Saída do painel escapada.
- Nome de backup validado por expressão regular antes de virar caminho de arquivo.
- Diretório de backups bloqueado por `.htaccess` (Apache) e com `index.php`.

### Conhecido

- O backup roda na requisição do admin e pode estourar tempo ou memória em sites grandes.
- A proteção do diretório depende de `.htaccess`; em nginx é preciso uma regra própria.
- Multisite não é suportado.
- Views, triggers, procedures e events não entram no dump.
- Apenas o backup mais recente é mantido: os nomes dos arquivos são fixos e são sobrescritos.
- Restauração, agendamento e lista de backups ainda não existem.
- O ZIP contém `wp-config.php` e o dump do banco; deve ser tratado como dado sensível.
