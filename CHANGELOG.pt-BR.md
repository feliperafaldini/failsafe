# Changelog

Todas as mudanças relevantes deste plugin são registradas neste arquivo.

Versão em inglês: [CHANGELOG.md](CHANGELOG.md).

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto usa versionamento semântico. O `readme.txt` em `src/` repete o histórico em inglês para o diretório do WordPress.org.

## [Não publicado]

### Planejado

- Suporte a multisite.
- Restauração dos arquivos do site pela interface.
- Processamento em background para sites grandes.
- Inclusão de views, triggers, procedures e events no dump.

Nada disso tem versão ou data definida.

## [0.2.0] - 2026-10-24

### Adicionado

- Página **Failsafe** no menu principal, com a tabela de backups (data, arquivos, tamanho e ações).
- Download do `.zip` e do `.sql` por `admin-post.php`, com nonce e caminho reconstruído a partir do nome validado do backup.
- Exclusão em POST com nonce por conjunto, removendo os dois arquivos de uma vez.
- Aviso de conjunto incompleto: um backup com apenas um dos arquivos é marcado na tabela e não é removido pela retenção.
- Widget do painel com o último backup, a quantidade de conjuntos e o tamanho total em disco, além do botão **Backup agora**.
- Nomes datados no formato `failsafe-YYYYMMDD-HHMMSS`, com sufixo numérico quando dois backups começam no mesmo segundo.
- Retenção de backups completos, com padrão 5, limite configurável de 0 a 50 e 0 para manter todos.
- Dump gravado em streaming, em blocos de 500 linhas por consulta e lotes de 100 `INSERT`, mantendo a memória constante independentemente do tamanho do banco.
- Escrita atômica do dump: arquivo `.part` movido para o destino pelo `WP_Filesystem`; um ZIP com falha é apagado para não parecer um backup válido.
- Restauração do banco em **Ferramentas > Failsafe Restore**, com confirmação digitada do nome do backup.
- Restauração lê o dump do disco em blocos de 64 KB e executa cada instrução assim que ela termina, sem carregar o arquivo inteiro.
- Restauração limitada a instruções que um dump precisa (`SET`, `DROP/CREATE/ALTER TABLE`, `INSERT`, `REPLACE`, `LOCK/UNLOCK TABLES`, controle de transação); qualquer outro comando aborta a operação.
- Importação em modo de commit único por execução: um dump de 38 MB caiu de 5min04s para cerca de 19s.
- Backup de segurança completo antes da restauração; se ele falhar, a restauração é abortada.
- Extração do ZIP para um diretório temporário fora do webroot, com rejeição de caminhos absolutos, referências `..`, bytes nulos e links simbólicos, e limite de 50.000 entradas.
- Execução do dump com verificação de erro por instrução; falhas em comandos `SET` são registradas e ignoradas, e as demais interrompem a restauração.
- O backup restaurado e o backup de segurança não são apagados pela rotina de restauração.
- Agendamento de backups com as frequências horária, duas vezes ao dia, diária e semanal, desligado ou ligado nas configurações e diário por padrão após a ativação.
- Lock de execução para backups agendados e manuais, com transiente de apoio e retomada de lock abandonado após 15 minutos.
- Tela de configurações com o estado do agendamento, a última execução, o último resultado, a próxima execução e o limite de retenção.
- Avisos na tela de configurações para evento atrasado, evento ausente e `DISABLE_WP_CRON`.
- Checagem prévia de memória (piso fixo de 64 MB, já que o dump é streaming), disco (cerca de 2 vezes o banco mais os arquivos do site, mais 16 MB) e tempo de execução (cerca de 5 segundos mais 1 segundo por 8 MB de banco e arquivos).
- Cache de 1 hora para o tamanho total dos arquivos do site, usado no cálculo de disco.
- Cache de 10 minutos para o tamanho estimado do banco, consultado em `information_schema`.
- E-mail de status para backups manuais: falha sempre que o endereço do administrador é válido, sucesso apenas com a opção `failsafe_email_on_success` ligada; destinatário configurável pelo filtro `failsafe_status_email_recipient`.
- Ação `failsafe_restore_completed` disparada após uma restauração bem-sucedida.
- `Requires PHP: 8.0` no cabeçalho do plugin e no `readme.txt`.

### Alterado

- O backup deixou de usar os nomes fixos `failsafe-backup.sql` e `failsafe-backup.zip` e passou a criar um conjunto datado por execução; versões antigas não são sobrescritas.
- O widget do painel deixou de ser apenas o formulário de backup e passou a resumir o último backup, a quantidade e o tamanho total.
- O resultado do backup manual agora redireciona para a página **Backups** com aviso de sucesso ou de erro, em vez de exibir apenas uma mensagem no widget.
- A retenção é aplicada depois de todo backup concluído, manual ou agendado.
- O ZIP contém o dump com o nome do conjunto (`failsafe-YYYYMMDD-HHMMSS.sql`); a restauração também aceita o nome fixo usado pela 0.1.0.
- O `Uninstall` remove todas as opções do plugin e o transient do lock; com `failsafe_delete_backups_on_uninstall` ligado, remove também o diretório de backups.

### Corrigido

- Um ZIP parcial deixou de aparecer como backup válido: o arquivo é apagado quando a criação falha.
- Backups simultâneos não se sobrescrevem mais; colisões no mesmo segundo recebem sufixo.
- Erros de banco durante o dump continuam interrompendo a execução em vez de gerar um arquivo incompleto.
- A restauração não grava arquivos do site no webroot: o conteúdo extraído é validado e descartado.
- Um backup de segurança é criado antes de qualquer escrita no banco; sem ele, a restauração não começa.
- O cache de objetos é limpo depois da restauração, mesmo quando ela falha no meio.

### Segurança

- Todas as ações de backup, download, exclusão e restauração exigem capability (`manage_options` por padrão, filtrável) e nonce.
- A exclusão só aceita POST, com nonce específico do conjunto.
- Nomes de backup são validados por expressão regular antes de virar caminho de arquivo.
- O download valida o caminho real contra o diretório de backups e recusa links simbólicos.
- A extração do ZIP recusa caminhos absolutos, referências ao diretório pai, bytes nulos e links simbólicos antes de extrair qualquer entrada.
- O diretório de backups continua protegido por `.htaccess` (Apache) e `index.php`; no nginx é preciso uma regra própria.
- O ZIP contém `wp-config.php` e o dump do banco; deve ser tratado como dado sensível.

### Conhecido

- O dump é gravado em streaming, com memória constante; o limite que resta é o tempo da requisição.
- O backup roda na requisição do admin ou do WP-Cron e pode atingir o `max_execution_time`; não há processamento em background.
- O WP-Cron só executa com tráfego; sites sem visitas podem atrasar o backup agendado.
- A proteção do diretório depende de `.htaccess`; no nginx é preciso uma regra própria.
- Multisite não é suportado.
- A restauração cobre apenas o banco; os arquivos do site não são restaurados.
- Uma restauração interrompida por erro pode deixar o banco parcialmente restaurado.
- Views, triggers, procedures e events não entram no dump.
- A retenção não remove conjuntos incompletos; eles ficam até a exclusão manual.
- O backup de segurança passa pela retenção; um conjunto fora do limite pode ser removido nesse momento, inclusive o que seria restaurado.
- Uma segunda execução durante um backup em andamento é recusada com aviso; ela não entra em fila.
- E-mails de status são enviados apenas por backups manuais; uma falha agendada fica registrada no status e no log.
- As opções `failsafe_email_on_success` e `failsafe_delete_backups_on_uninstall` não têm campo nas telas de configuração.
- Backups da 0.1.0 com nomes fixos não são listados nem removidos pela retenção.

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
