# Maturidade para uso em clientes — Failsafe Backup 0.2.0

- **Versão avaliada:** 0.2.0 (`FAILSAFE_VERSION` em `src/failsafe.php`)
- **Data desta avaliação:** 2026-10-25
- **Escopo:** instalação WordPress single-site
- **Ambiente de referência:** container local do projeto, `wordpress:latest` com WordPress 7.1.3, PHP 8.3.36, MariaDB 11.8.9 e Apache 2.4.69
- **Natureza do documento:** apoio à decisão de ativar o plugin em um cliente. Não é certificação, laudo ou garantia de desempenho. Números medidos no ambiente de desenvolvimento não se transferem automaticamente para produção.

## Resumo para decisão

O Failsafe Backup 0.2.0 é adequado para **um site pequeno ou médio, single-site, em Apache, com banco de poucos megabytes e alguns milhares de arquivos**. Nesse cenário ele cria, lista, baixa, agenda e restaura o banco, com proteção por nonce e capability, escrita atômica e backup de segurança antes da restauração.

Não é adequado, sem trabalho adicional, para:

- banco grande a ponto de o backup passar de 30 segundos dentro da requisição (os dados cabem, o tempo não);
- multisite (não suportado);
- nginx sem regra própria de bloqueio (o plugin depende de `.htaccess`);
- cliente que espera restauração dos arquivos do site pela interface (só o banco é restaurado);
- hospedagem com `memory_limit` de 128 MB e `max_execution_time` de 30 s sem margem para o tamanho real do site.

Três pontos precisam estar claros antes de vender a solução como "backup completo":

1. **O ZIP contém os arquivos, mas a restauração não os devolve.** A restauração executa apenas o dump do banco; os arquivos extraídos são descartados.
2. **O dump é gravado em streaming.** A memória é constante: 42 MB de pico com uma tabela de 150 mil linhas e 37,8 MB de dump, sob limite de 128 MB. Antes dessa mudança o mesmo teste morria por memória esgotada com 100 mil linhas. O limite que resta é tempo, não memória.
3. **O backup roda dentro de uma requisição.** O limite padrão do Apache é 30 segundos; o conjunto de referência levou cerca de 9 segundos.

## 1. Matriz de suporte

| Componente | Mínimo suportado | Verificado neste ambiente | Observação |
|---|---|---|---|
| WordPress | 6.2 | 7.1.3 | 6.2 até 7.0 **não testados** aqui. O cabeçalho e o `readme.txt` declaram 6.2+; `Tested up to: 7.1` ainda precisa de confirmação antes de publicar no diretório. |
| PHP | 8.0 | 8.3.36 | 8.0, 8.1 e 8.2 **não testados** aqui. O código usa funções de PHP 8.0 (`str_starts_with`, `str_ends_with`, `str_contains`). |
| Extensão `zip` | Obrigatória | Presente (`ZipArchive`) | Sem ela, backup e restauração falham com `WP_Error` (`failsafe_no_ziparchive`). Também é exigida pelo `bin/build.sh`. |
| Extensão `mbstring` | Obrigatória | Presente | `failsafe_sql_value()` chama `mb_check_encoding()` sem fallback. Sem `mbstring`, o dump aborta com erro fatal, não com `WP_Error`. |
| Extensão `mysqli` | Exigida pelo WordPress | Presente | Conexão do WordPress com o banco; o plugin usa `$wpdb`. |
| Banco | MySQL 5.7+ / MariaDB 10.4+ | MariaDB 11.8.9 | MySQL 5.7/8.x e MariaDB 10.4 a 10.11 **não testados**. O plugin usa `information_schema`, `SHOW CREATE TABLE`, `START TRANSACTION WITH CONSISTENT SNAPSHOT` e `DROP TABLE`/`CREATE TABLE`; todos existem nesses mínimos. |
| Servidor web | Apache 2.4 com `.htaccess` e `AllowOverride` ativo | Apache 2.4.69 | Diretório e arquivos retornaram **403** nas requisições de teste. Em nginx o `.htaccess` é ignorado: é obrigatório criar regra própria ou mover o diretório para fora do webroot. |
| Sistema operacional | Linux/POSIX com escrita em `wp-content` | Container Linux | Windows/IIS **não testado**. O plugin foi escrito e testado em Linux; permissões e caminhos devem ser validados em outro sistema. |
| Memória PHP | 128 MB (padrão do ambiente) | 128 MB | Recomendado 256 MB ou mais para produção. Ver seção 2. |
| Tempo de execução | 30 s (padrão do Apache) | 30 s sob Apache; 0 na CLI | O backup precisa caber no limite da requisição. WP-CLI não tem esse limite, mas não é o fluxo normal do painel. |
| Agendamento | WP-Cron com tráfego ou cron real | WP-Cron do container | Sem visitas e sem cron do sistema, o evento atrasa indefinidamente. |
| Multisite | Não suportado | Confirmado single-site | Em multisite, `SHOW TABLES` inclui todas as tabelas da rede e o ZIP inclui todos os arquivos, sem separação por site. |

### Combinações não verificadas

- WordPress 6.2 a 7.0 com PHP 8.0 a 8.2.
- MySQL 5.7/8.0 e MariaDB 10.4 a 10.11.
- nginx, IIS, LiteSpeed e qualquer servidor que ignore `.htaccess`.
- Proxy reverso, CDN ou hospedagem que imponha timeout menor que o do PHP.
- Instalação com `open_basedir` restringindo `get_temp_dir()` ou o diretório de backups.

## 2. Limites medidos

### 2.1 Ambiente e método

As medições foram feitas no container local do projeto (`failsafe-wordpress-1` e `failsafe-db-1`), com o plugin montado a partir de `src/`. O PHP do container não carrega `php.ini`; valem os padrões compilados: `memory_limit = 128M`, `max_execution_time = 30` no Apache e `0` na CLI. O `.htaccess` do diretório de backups foi verificado por HTTP.

Para o **teste de escala** foi usada uma tabela temporária de 100.000 linhas, criada no mesmo banco durante a janela de medição. Ela está identificada como teste de escala e não contamina os números de referência (1,3 MB de dump, 3.795 entradas, 37 MB de ZIP), que vêm de um conjunto gerado antes dessa tabela.

### 2.2 Números medidos

| Medida | Valor medido | Como foi obtido |
|---|---|---|
| Dump SQL do conjunto de referência | **1.330.340 bytes (1,27 MiB)** | Arquivo `failsafe-backup.sql` gerado pelo plugin no ambiente. |
| ZIP do conjunto de referência | **37.365.626 bytes (35,6 MiB; ~37,4 MB)** | Arquivo `failsafe-backup.zip`; 3.795 entradas no archive. |
| Entradas do ZIP de referência | **3.795** (inclui o dump) | `ZipArchive::numFiles` do ZIP de referência. |
| Tamanho atual do site (sem backups) | **136 MB, 6.839 arquivos** | `du -sh --exclude=failsafe-backups` e contagem de arquivos com `find`. O container tem hoje mais arquivos que o conjunto de referência; os dois números são medidos, mas não no mesmo estado do site. |
| ZIP atual, refeito com o mesmo código | **41.833.687 bytes (39,90 MiB), ~6.840 entradas** | Execução de `failsafe_create_zip()` sobre o dump de referência em 2026-10-25. |
| Tempo da etapa de ZIP atual | **9,02 s** | `failsafe_create_zip()` com 6.839 arquivos e 39,90 MiB de saída. É a maior parte do tempo total. |
| Tempo total do conjunto de referência | **~9 s** | Valor registrado no `README.md` e no `readme.txt`, coerente com a etapa de ZIP medida. Não foram somados os 9,02 s atuais, que se referem a um site maior. |
| Pico de memória da etapa de ZIP | **53,3 MiB** | `memory_get_peak_usage(true)` após `failsafe_create_zip()`. Não inclui a montagem do dump. |
| Limite de memória do PHP | **128 MB** | `ini_get('memory_limit')` na CLI; sem `php.ini` carregado, o padrão compilado também vale para o Apache. |
| Timeout do Apache | **30 s** | Padrão do PHP para o SAPI Apache; a CLI reporta `0`. |
| Proteção do diretório no Apache | **403** para `/failsafe-backups/`, `.sql` e `.zip` | `curl` contra `http://localhost:8080`. |
| Teste de escala: backup com tabela de 150.000 linhas | **37,8 MiB de dump e 42,0 MiB de ZIP em 8,0 s**, pico de **42,0 MiB** | `failsafe_write_sql_dump()` + `failsafe_create_zip()` com `memory_limit=128M`. |
| Teste de escala: restauração do mesmo dump | **19,3 s**, pico de **38,0 MiB**, 150.000 linhas conferidas | `failsafe_restore_database_from_file()` com `memory_limit=128M`, medido após o commit em lote. |
| Antes do streaming (referência do problema) | **falha por memória** com 100.000 linhas | `Allowed memory size of 134217728 bytes exhausted` em `class-wpdb.php`. |
| Retenção padrão | **5 conjuntos** | `FAILSAFE_RETENTION_DEFAULT`; faixa configurável de 0 a 50. |

### 2.3 O que é medição e o que é projeção

**Medido:**

- tamanho do dump, do ZIP e do site;
- quantidade de arquivos e de entradas no ZIP;
- tempo da etapa de ZIP e pico de memória dessa etapa;
- presença de `zip`, `mbstring` e `mysqli`;
- limites de PHP e comportamento do `.htaccess`;
- pico de memória e tempo do teste de escala (150 mil linhas, 37,8 MiB de dump, 42,0 MiB de ZIP);
- tempo e pico de memória da restauração do mesmo dump (19,3 s e 38,0 MiB).

**Projetado (não medido):**

- **Tempo até o timeout:** há um total de ~9 s registrado para 3.795 entradas (referência) e uma medição de 9,02 s para a etapa de ZIP com 6.839 arquivos (atual). Uma projeção linear grosseira, só para ordem de grandeza, colocaria o limite de 30 s em torno de 20 mil a 23 mil arquivos. Não há medição nesse tamanho; compressão, disco e banco mudam a curva. Não use essa projeção como promessa.
- **Banco grande:** o plugin estima 5 s + 1 s por 2 MB de banco. Pelo modelo dele, um banco acima de ~50 MB chegaria aos 30 s. O teste medido (37,8 MiB de dump em 8,0 s) sugere que o modelo é conservador, mas não há medição acima desse tamanho.
- **Memória:** deixou de escalar com o banco. A checagem prévia exige um piso fixo de 64 MB; medido 42 MiB de pico no backup e 38 MiB na restauração.
- **Disco:** a checagem reserva 2 vezes (banco + arquivos do site) + 16 MB, e o tamanho dos arquivos é medido e cacheado por 1 hora.
- **Restauração:** medida neste ambiente com 37,8 MiB de dump: 19,3 s e 38 MiB de pico. Acima disso, o tempo tende a passar dos 30 s de uma requisição Apache; restaure pela CLI ou divida o banco.

**Não testado:**

- banco de produção, com dezenas ou centenas de megabytes;
- restauração real de um site em produção;
- PHP 8.0 a 8.2, WordPress 6.2 a 7.0, MySQL e MariaDB nas versões mínimas;
- nginx e IIS;
- ambiente com object cache externo, `open_basedir` ou disco de rede.

### 2.4 Leitura dos números

O conjunto de referência tem **1,3 MB de dump** e **37 MB de ZIP**. A diferença é o site: o ZIP inclui o núcleo do WordPress, temas, plugins e uploads, não só o banco. Uma retenção de 5 conjuntos nesse tamanho ocupa cerca de **185 MB**; com o núcleo atual, **~210 MB**. Some o dump, o `.part` temporário e o diretório de extração da restauração (do tamanho do site) ao planejar o disco.

O tempo é dominado pela varredura e compressão dos arquivos, não pelo dump pequeno. Por isso um banco maior piora o tempo de forma gradual, mas muitos arquivos e um disco lento também podem estourar os 30 s.

## 3. O que o plugin faz e não faz

### 3.1 Faz

| Capacidade | Detalhe |
|---|---|
| Backup manual | Botão **Backup agora** no widget do painel e na página **Failsafe**, com nonce e capability (`manage_options` por padrão, filtrável por `failsafe_backup_capability`). |
| Conjunto datado | `failsafe-YYYYMMDD-HHMMSS.sql` + `.zip`; um backup novo não sobrescreve o anterior. Colisões no mesmo segundo recebem sufixo `-2` a `-9`. |
| Dump do banco | Todas as tabelas do banco atual, com `DROP TABLE IF EXISTS`, `CREATE TABLE`, um `INSERT` por linha, snapshot consistente, `FOREIGN_KEY_CHECKS = 0` e `COMMIT`. Erro de banco vira `WP_Error` e interrompe o backup. |
| ZIP do site | Dump na raiz e todos os arquivos legíveis do site, exceto o diretório de backups, `wp-content/cache` e `wp-content/upgrade`. |
| Escrita atômica | O dump é gravado como `.part` e renomeado; ZIP incompleto é apagado. |
| Listagem e download | Página **Failsafe** com data, arquivos, tamanho, download do `.zip` e do `.sql` por `admin-post.php` com nonce. |
| Exclusão | POST com nonce por conjunto; remove os dois arquivos. |
| Retenção | Remove conjuntos completos acima do limite; padrão 5, faixa 0 a 50, 0 mantém todos. Conjuntos incompletos não são removidos automaticamente. |
| Agendamento | Frequências horária, duas vezes ao dia, diária e semanal; ligado/desligado nas configurações; lock contra execução simultânea e retomada de lock abandonado após 15 minutos. |
| Restauração do banco | **Ferramentas > Failsafe Restore**, com confirmação digitada do nome, backup de segurança completo antes da restauração e execução do dump com verificação de erro por instrução. |
| Checagem prévia | Memória, disco e tempo antes de iniciar o dump; interrompe o caso previsível com mensagem acionável. |
| E-mail de status | Falha de backup manual para o e-mail do administrador; sucesso só com `failsafe_email_on_success` ligado. |
| Proteção do diretório | `.htaccess` (Apache) e `index.php`; o diretório é criado na ativação e verificado a cada acesso ao admin. |

### 3.2 Não faz

| Limitação | Consequência prática |
|---|---|
| **Não restaura arquivos do site** | A restauração executa apenas o dump do banco. Os arquivos do ZIP são extraídos para um diretório temporário, validados e descartados. Uploads, temas, plugins e `wp-config.php` não são sobrescritos. Recuperar arquivos exige extrair o ZIP manualmente com o site offline. |
| **Não é multisite** | Não há separação por site da rede; o dump mistura as tabelas de todos os sites. Não usar em multisite. |
| **Não inclui views, triggers, procedures e events** | Só tabelas base entram no dump. Aplicações que dependem desses objetos precisam de exportação complementar (`mysqldump --routines --triggers --events` ou equivalente). |
| **Não roda em background** | O backup executa na requisição do admin ou do WP-Cron. Não há fila, job assíncrono ou retomada. |
| **Não tem restauração pontual nem incremental** | Cada conjunto é completo; não há backup diferencial nem point-in-time recovery. |
| **Não criptografa** | O ZIP é gravado em texto claro e contém `wp-config.php` e o dump com hashes de senha e dados pessoais. |
| **Não envia para storage externo** | Não há S3, Google Drive, FTP ou destino remoto. O backup fica no disco local até ser copiado por fora. |
| **Não restaura só o `.sql` pela tela** | É preciso um `.zip` não vazio. O `.sql` isolado só pode ser importado por fora (WP-CLI/`mysql`). |
| **Não tem comando WP-CLI próprio** | É possível chamar `failsafe_create_backup()` por `wp eval`, mas isso não é uma interface suportada e ignora o lock do fluxo normal. |
| **Não alerta falha agendada por e-mail** | Falha agendada fica registrada em **Configurações > Failsafe Backup** e no `debug.log`; o e-mail de status só é enviado por backup manual. |
| **Não protege sozinho em nginx** | Depende de `.htaccess`. Sem regra no servidor, os arquivos podem ficar acessíveis por URL direta. |
| **Não tem tela para duas opções** | `failsafe_email_on_success` e `failsafe_delete_backups_on_uninstall` precisam ser definidas por código ou na tabela de options. |
| **Não lista backups da 0.1.0** | Os nomes fixos `failsafe-backup.sql` e `failsafe-backup.zip` não são listados nem removidos pela retenção. |
| **Não corrige banco parcialmente restaurado** | Uma falha no meio do dump pode deixar o banco parcialmente restaurado. O backup de segurança é a saída. |

## 4. Procedimento de rollback

O rollback tem duas partes independentes: **código do plugin** e **banco de dados**. Atualizar o plugin não altera arquivos do site nem o banco por si só, mas uma versão nova pode gravar options, agendar eventos ou falhar no carregamento. Volte as duas partes na ordem correta.

### 4.1 Antes de qualquer atualização

1. Gere um backup completo pelo plugin e confira que o `.zip` foi criado:

   ```sh
   wp eval 'var_export( failsafe_create_backup() );'
   ```

2. Copie o conjunto para fora do servidor e guarde também o ZIP da versão antiga do plugin:

   ```sh
   cp -a wp-content/failsafe-backups/failsafe-YYYYMMDD-HHMMSS.zip /backup-externo/
   cp -a wp-content/plugins/failsafe-backup /backup-externo/failsafe-plugin-antes-da-atualizacao
   ```

3. Anote a versão atual:

   ```sh
   wp plugin get failsafe-backup --field=version
   ```

4. Faça o download do `.zip` da versão antiga (o mesmo arquivo usado na instalação) e guarde-o. Sem ele, o rollback de código depende da cópia do diretório.

### 4.2 Voltar a versão do plugin

Se o painel ainda abre, o caminho mais simples é reinstalar o ZIP anterior. O WordPress não faz downgrade "automático": é preciso substituir a pasta.

**Com WP-CLI:**

```sh
# Evita carregar o plugin com problema durante a desativação.
wp --skip-plugins=failsafe-backup plugin deactivate failsafe-backup

# Substitui a versão atual pela anterior e ativa a versão antiga.
wp plugin install /caminho/failsafe-0.1.0.zip --force
wp plugin activate failsafe-backup

# Confere.
wp plugin list --name=failsafe-backup --fields=name,status,version
```

**Sem WP-CLI (SSH/SFTP):**

```sh
mv wp-content/plugins/failsafe-backup wp-content/plugins/failsafe-com-problema
unzip /caminho/failsafe-0.1.0.zip -d wp-content/plugins/
chown -R www-data:www-data wp-content/plugins/failsafe-backup  # ajuste para o usuário do servidor
```

Ao renomear a pasta, o WordPress desativa o plugin sozinho na próxima requisição. Depois, ative a versão anterior em **Plugins**.

**Se o site estiver em erro fatal e o painel não abrir**, renomeie a pasta por SSH/SFTP; o WordPress volta a carregar sem o plugin. Guarde uma cópia da pasta com problema e do `wp-content/debug.log` antes de apagar qualquer coisa.

### 4.3 Restaurar o banco

**Pelo próprio plugin (preferido):**

1. Faça login em **Ferramentas > Failsafe Restore**.
2. Escolha o conjunto criado imediatamente antes da mudança.
3. Digite o nome exato do conjunto para confirmar.
4. Envie. O plugin cria um backup de segurança completo antes de tocar no banco; se esse backup falhar, a restauração é abortada.
5. Depois da restauração, confira login, usuários, permalinks, widgets e opções do tema. A sessão pode exigir novo login.

**Ordem importa ao voltar para a 0.1.0:** a 0.1.0 não lista conjuntos datados. Se o destino do rollback for a 0.1.0, **restaure o banco com a 0.2.0 ainda ativa** (ou importe o `.sql` por WP-CLI) e só depois troque o código. Caso contrário, o backup datado fica inacessível pela interface antiga.

**Se o painel não abrir (fallback por WP-CLI):**

```sh
# Execute a partir da raiz do site.

# 1. Garanta que nenhum backup está rodando e desative o plugin.
wp --skip-plugins=failsafe-backup plugin deactivate failsafe-backup

# 2. Exporte o estado atual antes de sobrescrever (segurança extra).
wp db export /backup-externo/antes-do-rollback.sql

# 3. Importe o dump do conjunto. O dump recria as tabelas (DROP TABLE + CREATE TABLE).
wp db import wp-content/failsafe-backups/failsafe-YYYYMMDD-HHMMSS.sql

# 4. Limpe o cache de objetos, se houver cache externo.
wp cache flush
```

O `wp db import` usa as credenciais do `wp-config.php`, então não é preciso colocar senha na linha de comando. Aviso: esse caminho **não** cria o backup de segurança automático e **não** valida o ZIP; faça o export do passo 2 e teste antes em staging.

### 4.4 Desfazer uma restauração ruim

Antes de tocar no banco, o plugin cria um conjunto de segurança com timestamp do momento. Para desfazer:

1. Abra **Ferramentas > Failsafe Restore**.
2. Escolha o conjunto de segurança criado no momento da restauração que deu errado.
3. Confirme e restaure.

Se o limite de retenção estiver baixo, o backup de segurança criado antes da restauração pode remover conjuntos antigos, inclusive **o conjunto que seria restaurado**: ele não é protegido durante a criação do backup de segurança. Aumente a retenção **antes** de restaurar, ou copie o conjunto escolhido para fora do servidor.

### 4.5 Depois do rollback

```sh
wp plugin list --name=failsafe-backup --fields=name,status,version
wp cron event list --hook=failsafe_cron_backup
wp eval 'var_export( failsafe_cron_status() );'
curl -s -o /dev/null -w '%{http_code}\n' https://exemplo.com/wp-content/failsafe-backups/
```

- O esperado no `curl` é **403**. Se vier 200, o `.htaccess` não está sendo aplicado.
- Se houver lock preso e **não houver backup rodando**, remova:

  ```sh
  wp option delete failsafe_cron_lock
  wp transient delete failsafe_cron_lock
  ```

  Nunca remova o lock durante uma execução; o resultado pode ser dois backups gravando ao mesmo tempo.
- Reative o agendamento em **Configurações > Failsafe Backup** e confirme a próxima execução.
- Verifique `wp-content/debug.log` e o status do último backup.

## 5. Antes de ativar em cliente

Checklist mínimo. Itens sem evidência devem ser tratados como risco, não como formalidade.

### 5.1 Ambiente

- [ ] **Single-site confirmado.** `wp eval 'var_dump( is_multisite() );'` deve retornar `false`. Se for multisite, não ative.
- [ ] **WordPress 6.2+ e PHP 8.0+.** `wp core version` e `wp eval 'echo PHP_VERSION;'`.
- [ ] **Extensões presentes.** `wp eval 'var_dump( class_exists( "ZipArchive" ), function_exists( "mb_check_encoding" ) );'` deve retornar `true` para as duas.
- [ ] **Memória.** Confira o `memory_limit` no **SAPI web** (Ferramentas > Saúde do site > Informações > Servidor, ou `phpinfo()` do Apache). `wp eval` roda na CLI, onde o WP-CLI costuma definir `memory_limit = -1`; esse valor não vale para o painel. Recomendado **256 MB ou mais**; 128 MB só para banco pequeno e com teste.
- [ ] **Timeout.** Confirme `max_execution_time` no PHP do Apache e o timeout do proxy/CDN. O padrão de 30 s é apertado para o fluxo de restauração, que ainda cria um backup de segurança. A CLI reporta `0` e não representa o painel.
- [ ] **Banco.** Meça o tamanho real e teste com uma cópia. Não confie só na checagem prévia: ela usa 3× o banco para memória e ignora os arquivos do site no disco.
- [ ] **Disco.** Livre o suficiente para retenção × tamanho do conjunto + uma extração completa do ZIP + margem. Para o site de referência (136 MB, conjunto de 40 MB, retenção 5), reserve **pelo menos 1 GB**. A checagem do plugin não cobre essa conta.
- [ ] **Permissões.** `wp-content` gravável pelo usuário do servidor web; o diretório `wp-content/failsafe-backups` criado e com dono correto. Verifique com `namei -l` e `ls -ld`.
- [ ] **`.htaccess` ativo.** Confirme `AllowOverride` no vhost e teste **um arquivo real**, não só o diretório:

  ```sh
  curl -s -o /dev/null -w '%{http_code}\n' https://exemplo.com/wp-content/failsafe-backups/failsafe-YYYYMMDD-HHMMSS.sql
  ```

  O esperado é **403**. Diretório com 403 pode ser apenas o `index.php`; o teste no `.sql` prova a regra.
- [ ] **nginx, se for o caso.** Adicione e teste a regra `location ~* /wp-content/failsafe-backups/ { deny all; }` ou defina `FAILSAFE_BACKUP_DIR` em `wp-config.php` para um caminho fora do webroot, antes de o plugin carregar.
- [ ] **WP-Cron.** Confirme que o site tem tráfego ou configure cron real (recomendado mesmo sem `DISABLE_WP_CRON`). Se `DISABLE_WP_CRON` estiver definido, o cron do sistema passa a ser obrigatório. Agende:

  ```cron
  */5 * * * * cd /caminho/do/site && /usr/local/bin/wp cron event run --due-now >/dev/null 2>&1
  ```

  Alternativa sem WP-CLI: chamar `https://exemplo.com/wp-cron.php?doing_wp_cron` a cada 5 minutos. Sem isso, o backup agendado atrasa.
- [ ] **E-mail.** Confirme que o endereço do administrador recebe e-mail. Lembre que falha agendada **não** gera e-mail; monitore a tela de configurações.
- [ ] **Retenção.** Defina o número de conjuntos considerando o disco. `0` mantém tudo e pode encher o disco.
- [ ] **Backup do próprio backup.** O ZIP local não substitui cópia externa. Configure a cópia para outro servidor/storage e teste a restauração dela.

### 5.2 Teste de restore em staging

- [ ] Crie um staging com a **mesma versão de PHP, WordPress e banco** do cliente.
- [ ] Gere um backup no staging e restaure pelo próprio plugin.
- [ ] Valide: login de administrador, lista de usuários, permalinks, home, widgets, menus, opções do tema, plugin de formulários e comércio.
- [ ] Teste também o cenário de desastre: apague uma opção ou uma linha de teste, restaure e confirme que voltou.
- [ ] Meça o tempo e o pico de memória da restauração no staging. A restauração carrega o dump inteiro com `file_get_contents()` e o divide em instruções; o consumo tende a ser maior que o do backup, mas não foi medido nesta avaliação.
- [ ] Confirme o que **não** volta: uploads, tema, plugins e `wp-config.php`. Se o cliente espera recuperar arquivos, o procedimento é extrair o ZIP manualmente com o site offline, não usar a tela.
- [ ] Teste o download do `.zip` por HTTPS e confirme que o arquivo baixado abre (`unzip -t`).
- [ ] Teste a proteção por URL direta no staging, com um `.sql` real.

### 5.3 Operação

- [ ] Documente para o cliente onde ficam os backups, quem pode baixá-los e que eles contêm dados sensíveis.
- [ ] Defina janela de manutenção para restaurações; a restauração não coloca o site em maintenance mode e pode deixar o banco parcial em caso de falha.
- [ ] Monitore a primeira execução agendada em **Configurações > Failsafe Backup** e no `debug.log`.
- [ ] Exclua backups antigos e conjuntos incompletos manualmente quando necessário; a retenção não remove conjuntos incompletos.
- [ ] Se vier de uma versão 0.1.0, trate os arquivos de nome fixo antes/depois do upgrade: eles não aparecem na lista nova.

## 6. Riscos residuais conhecidos

| Risco | Efeito | O que fazer |
|---|---|---|
| **Banco grande em memória** | O dump é montado como uma única string. No teste de escala, um dump de 25,56 MiB teve pico de **191,3 MiB**, que excederia os 128 MB do SAPI web. A checagem prévia (3× o banco) pode liberar o backup e ele ainda estourar. | Suba `memory_limit` para 256 MB ou 512 MB e teste com uma cópia do banco de produção. Para bancos na casa das dezenas de MB, não ative sem streaming (não implementado). Rode o teste de carga em staging, não em produção. |
| **Timeout de 30 s** | A requisição é interrompida; o `.part` e um ZIP parcial podem ficar para trás, e o backup não é concluído. A restauração também pode estourar. | Meça o tempo real no cliente. Suba `max_execution_time` e o timeout do proxy. Rode fora do horário de pico. Depois de uma falha, apague `.part` e conjuntos incompletos; a retenção não os remove. Como paliativo operacional, `wp eval 'failsafe_create_backup();'` roda na CLI sem timeout, mas **não** usa o lock: garanta que nenhum backup agendado esteja rodando. |
| **nginx sem regra** | O `.htaccess` é ignorado; `.sql` e `.zip` podem ser baixados por qualquer pessoa que descubra a URL. | Aplique `location ~* /wp-content/failsafe-backups/ { deny all; }` e teste com um `.sql` real. Ou mova o diretório para fora do webroot com `FAILSAFE_BACKUP_DIR` em `wp-config.php`. |
| **Lock sem fila** | Uma segunda execução simultânea é recusada com "Another backup is already running."; não há fila. Lock órfão bloqueia por até 15 minutos. | Não dispare manual e agendado no mesmo horário. Se houver lock preso e nenhum backup rodando, remova `failsafe_cron_lock` (option e transient). Não remova durante a execução. |
| **Arquivo sensível** | O ZIP contém `wp-config.php`, o dump do banco, hashes de senha e dados pessoais, sem criptografia. Vazamento expõe credenciais do banco e permite acesso aos dados. | Trate como confidencial: HTTPS no download, diretório fora do webroot ou bloqueado, permissões restritas (por exemplo 750/640), cópia externa criptografada. Se vazar, rotacione a senha do banco e as salts do `wp-config.php` (as salts invalidam sessões). |
| **Restauração parcial** | `DROP TABLE`/`CREATE TABLE` fazem commit implícito; uma falha no meio deixa o banco parcialmente restaurado. | O plugin cria backup de segurança antes; se a restauração falhar, restaure esse conjunto. Teste em staging e faça a operação em janela de manutenção. |
| **Retenção apaga o conjunto que seria restaurado** | O backup de segurança criado antes da restauração passa pela retenção; com limite baixo, ele pode remover conjuntos antigos, inclusive o escolhido para restaurar. | Aumente a retenção antes de restaurar ou copie o conjunto escolhido para fora. O conjunto usado só é protegido durante a execução da restauração. |
| **Memória/disco na restauração** | A restauração carrega o dump inteiro e extrai o ZIP completo para um diretório temporário. Pode estourar memória ou disco, mesmo que o backup tenha funcionado. | Reserve disco para a extração (tamanho do site) e memória para o dump. Teste o restore no staging com o mesmo tamanho. Não medido nesta avaliação. |
| **Arquivos não são restaurados** | Banco e arquivos ficam em versões diferentes; o site pode quebrar por tema/plugin incompatível. | Controle temas/plugins por deploy ou versionamento. Para recuperar arquivos, extraia o ZIP manualmente com o site offline. |
| **Checagem de disco ignora os arquivos** | O plugin reserva 2× o banco + 16 MB, mas o ZIP é dominado por arquivos. O disco pode acabar no meio. | Calcule manualmente: retenção × conjunto + extração + margem. Monitore o disco. |
| **Checagem de tempo ignora a quantidade de arquivos** | O modelo é 5 s + 1 s por 2 MB de banco; um site com muitos arquivos pode passar do limite mesmo com banco pequeno. | Meça o tempo no cliente. O conjunto de referência leva ~9 s; trate 30 s como orçamento total, não só do dump. |
| **WP-Cron depende de tráfego** | Backup agendado atrasa ou não roda em site sem visitas. | Configure cron real (WP-CLI ou `wp-cron.php`) e monitore a próxima execução. |
| **Multisite** | Dump inclui todas as tabelas da rede; não há restauração por site. | Não usar em multisite. |
| **Views, triggers, procedures, events** | Não entram no dump; a restauração pode quebrar funcionalidades que dependem deles. | Exporte esses objetos separadamente e documente a restauração em duas etapas. |
| **Conjunto incompleto** | Um ZIP ou SQL faltando (ou um `.part` de uma execução interrompida) não é removido pela retenção e não é restaurável pela tela se o ZIP faltar. | Verifique a lista e o diretório após falhas e exclua manualmente. Monitore o espaço. |
| **Backups da 0.1.0** | Nomes fixos não são listados nem removidos pela retenção. | Migre ou apague manualmente antes/depois do upgrade. |
| **Falha agendada sem e-mail** | O cliente pode achar que tem backup quando o agendamento está falhando. | Monitore a tela de configurações e o `debug.log`; se necessário, crie alerta externo. |
| **Opções sem interface** | `failsafe_email_on_success` e `failsafe_delete_backups_on_uninstall` não têm campo nas telas. | Defina por código ou pela tabela de options; documente no runbook do cliente. |
| **Atualização do WordPress** | O núcleo cresce e o ZIP aumenta (o conjunto de referência tinha 3.795 arquivos; o núcleo atual, 6.839). | Reavalie disco e tempo após atualizações grandes do WordPress. |

## 7. Evidências e reprodutibilidade

Comandos usados para os números desta avaliação (sem credenciais no documento):

```sh
# Versões e limites.
docker exec failsafe-wordpress-1 php /tmp/wp-cli.phar --allow-root --path=/var/www/html core version
docker exec failsafe-wordpress-1 php -r 'echo PHP_VERSION, " ", ini_get("memory_limit"), "\n";'
docker exec failsafe-db-1 mariadb -N -e "SELECT VERSION();"

# Tamanhos e contagem de arquivos.
docker exec failsafe-wordpress-1 sh -c 'find /var/www/html -type f -not -path "*/failsafe-backups/*" | wc -l'
docker exec failsafe-wordpress-1 sh -c 'du -sh --exclude=failsafe-backups /var/www/html'
docker exec failsafe-wordpress-1 php -r '$z = new ZipArchive(); $z->open("/var/www/html/wp-content/failsafe-backups/failsafe-backup.zip"); echo $z->numFiles, "\n";'

# Tempo e pico de memória da etapa de ZIP.
docker exec failsafe-wordpress-1 php /tmp/wp-cli.phar --allow-root --path=/var/www/html eval '
$t = microtime(true);
$r = failsafe_create_zip("/var/www/html/wp-content/failsafe-backups/failsafe-backup.sql", "/tmp/medicao.zip");
echo (microtime(true) - $t), " s; pico ", memory_get_peak_usage(true), " bytes\n";
unlink("/tmp/medicao.zip");
'

# Proteção do diretório.
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/wp-content/failsafe-backups/
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/wp-content/failsafe-backups/failsafe-backup.sql
```

A suíte `tests/run-tests.sh` cobre lint, testes unitários e round-trip de importação/reimportação. Ela **não foi reexecutada nesta avaliação**; portanto, este documento não apresenta um resultado novo de suíte. O round-trip importa o dump mais recente do diretório de backups, então deve ser rodado com o ambiente sem outra execução em andamento.

## 8. Recomendação final

Para um cliente pequeno, com banco de até poucos megabytes, site de até alguns milhares de arquivos, Apache com `.htaccess` funcionando e cron real:

- **Pode ativar**, desde que o checklist da seção 5 seja cumprido e o restore seja testado em staging.
- **Não prometa** restauração de arquivos, backup ilimitado, criptografia, envio externo ou suporte a multisite.
- **Não use** a checagem prévia como garantia de memória, disco ou tempo; ela é um alarme para os casos previsíveis.
- **Orçe** memória e timeout com margem: 256 MB e 60 s, no mínimo, para um site desse porte.
- **Comunique** que o ZIP é dado sensível e que o backup local precisa de cópia externa.

Para banco acima de ~20 a 30 MB, multisite, nginx sem regra ou expectativa de recuperação de arquivos, a resposta é **não ativar** até que o trabalho de escala/escopo correspondente exista e seja medido.
