# Iteração 5 — limites, e-mail de status e escrita atômica

## Resumo

Foram criados a verificação de limites antes do backup, o e-mail de status e os testes puros das funções de cálculo. Nenhum arquivo fora da lista de propriedade foi alterado: `actions.php`, `storage.php` e `failsafe.php` continuam intactos. A ligação no handler e o `require_once` ficam a cargo do agente principal, com os trechos exatos abaixo.

## Arquivos

| Arquivo | Estado |
| --- | --- |
| `src/includes/limits.php` | novo |
| `src/includes/notifications.php` | novo |
| `tests/test-limits.php` | novo |

Nenhum arquivo existente foi modificado.

## API entregue

### `src/includes/limits.php`

- `failsafe_check_limits(): true|WP_Error` — soma `DATA_LENGTH + INDEX_LENGTH` de `information_schema.TABLES` para o schema atual, compara com `memory_limit` (fator 3x), com `disk_free_space( failsafe_backup_dir() )` e com `max_execution_time` (estimativa de duração).
- `failsafe_estimate_database_size(): int|WP_Error` — tamanho do banco atual, cacheado na option `failsafe_db_size_cache` (autoload `false`) por `FAILSAFE_DB_SIZE_CACHE_TTL` (600 s).
- `failsafe_clear_database_size_cache(): void` — descarta o cache; útil depois de restauração/importação.
- Helpers puros, testáveis sem banco:
  - `failsafe_parse_size()` — aceita `128M`, `1G`, `512K`, `1.5G`, bytes puros, `-1` (sem limite) e `0` (desconhecido).
  - `failsafe_required_memory_bytes( $bytes, $factor = null )` — teto de `bytes × fator`, mínimo 1.
  - `failsafe_required_disk_bytes( $bytes )` — `2 × bytes + 16 MB`.
  - `failsafe_estimate_backup_seconds( $bytes )` — `5 s + bytes / 2 MB/s`.
  - `failsafe_available_disk_bytes( $dir )` — bytes livres ou `-1` quando não dá para ler.
- Códigos de erro: `failsafe_limit_memory`, `failsafe_limit_disk`, `failsafe_limit_time`, `failsafe_db_size_query_failed`, `failsafe_no_database`. Mensagens em inglês, em `__()`, com comentário de tradutores.
- Constantes ajustáveis em `wp-config.php` antes do plugin carregar (todas com guarda `defined()`): `FAILSAFE_LIMIT_SAFETY_FACTOR` (3), `FAILSAFE_DB_SIZE_CACHE_TTL` (600), `FAILSAFE_DISK_SAFETY_FACTOR` (2), `FAILSAFE_DISK_MARGIN_BYTES` (16 MB), `FAILSAFE_BACKUP_BASE_SECONDS` (5), `FAILSAFE_DB_BYTES_PER_SECOND` (2 MB).

### `src/includes/notifications.php`

- `failsafe_send_status_email( $result, $duration = null ): bool` — envia para `get_option( 'admin_email' )` via `wp_mail()`. Falha sempre envia; sucesso só com `get_option( 'failsafe_email_on_success', false )`.
  - O segundo parâmetro é opcional; se omitido, usa `$result['duration']` (segundos). A assinatura exigida (`$result`) continua válida.
  - Corpo em texto puro e inglês: site, status, tamanho do dump, tamanho do arquivo, caminho do ZIP, duração e, em falha, código + mensagem do `WP_Error` e tamanho estimado do banco.
  - Filtro `failsafe_status_email_recipient` para trocar o destinatário (útil em teste/homologação).
  - Falha de envio não derruba o backup: devolve `false` e registra via `failsafe_log()` quando disponível.

## Decisões e desvios conscientes

1. **`failsafe_estimate_database_size()` devolve `int|WP_Error`, não só `int`.** O `AGENTS.md` proíbe falha silenciosa; devolver `0` quando o `information_schema` falha faria o `check_limits` liberar o backup sem medir nada. O chamador (o próprio `failsafe_check_limits`) trata o `WP_Error`. É o único ponto em que a assinatura literal do TASK foi ampliada.
2. **`failsafe_send_status_email()` devolve `bool`** como pedido. E-mail é best-effort: a falha de entrega é registrada, mas não muda o resultado do backup (que já pode ter sido concluído). Um `WP_Error` aqui obrigaria o handler a decidir entre dois erros concorrentes.
3. **`0` e valores ilegíveis são "desconhecido", não "sem memória".** `memory_limit = 0`/vazio/ inválido e `max_execution_time = 0` pulam a checagem; `-1` também. Isso evita bloquear backups por configuração exótica — o PHP já falharia por conta própria se o valor fosse realmente impeditivo.
4. **Checagem de disco é conservadora:** `2 × banco + 16 MB`. O TASK diz "contra o mesmo tamanho estimado"; usei o tamanho estimado como base com folga para o dump + ZIP + `.part`. O tamanho dos arquivos do site não entra na conta (medi-lo custaria tanto quanto o backup); ver limitações.
5. **Estimativa de tempo é um modelo, não uma medição:** `5 s` fixos (varredura do site/abertura do ZIP) mais `1 s` por `2 MB` de banco. O alvo é pegar o caso óbvio de `max_execution_time = 30 s` com banco grande. Um backup local pequeno roda em ~9 s, dentro do modelo.
6. **Cache em option, não transient**, como pedido. `update_option( ..., false )` para não autoloadar; a entrada tem `bytes` e `time`.
7. **Nada de escrita de arquivo nos dois arquivos novos.** A escrita atômica é feita pelo handler usando `failsafe_write_file_atomic()` (já em `storage.php`); o trecho exato está abaixo. Não houve reimplementação.
8. **Textos de UI/e-mail em inglês** dentro de `__( '...', 'failsafe' )`, conforme o TASK e por causa da submissão ao WordPress.org; este relatório fica em português, conforme o `AGENTS.md`.
9. **`failsafe_status_email_log()`** foi extraída para não duplicar a lógica de `failsafe_log()` (que vive em `actions.php`, arquivo de outro agente) e para o teste rodar sem ela.

## Verificação executada

- `php -l src/includes/limits.php` — limpo.
- `php -l src/includes/notifications.php` — limpo.
- `php -l tests/test-limits.php` — limpo.
- `php tests/test-limits.php` (host, sem WordPress nem banco) — **30 checks, 0 failures**. Cobre `128M`, `1G`, `512K`, `2T`, `1.5G`, bytes puros, minúsculas, espaços, `-1`, `0`, vazio, lixo, inteiros; fator 3x, fator customizado, piso de 1 e arredondamento para cima; disco e tempo.
- Integração dentro do container `failsafe-wordpress-1` (arquivos copiados para `/tmp`, sem tocar no `src` montado do repo principal), com banco real: **34 checks, 0 failures**.
  - `failsafe_estimate_database_size()` mediu 2.211.840 bytes e gravou o cache; a segunda chamada usou o cache.
  - `failsafe_check_limits()` passou com os padrões do container (`memory_limit=128M`, `max_execution_time=30`, disco livre).
  - Forçando cache de 100 MB → `failsafe_limit_memory`; com `memory_limit=-1` e cache de 1 TB → `failsafe_limit_disk`; com `max_execution_time=2` → `failsafe_limit_time`.
  - E-mail: sucesso silencioso por padrão; falha sempre envia; sucesso com a option ligada envia; corpo traz tamanhos, duração, caminho e erro; `admin_email` inválido é recusado; `wp_mail()` retornando `false` vira `false` sem fatal. `pre_wp_mail` foi usado para interceptar (nenhum e-mail real saiu).
  - Options e `admin_email` restaurados ao estado original; `/tmp/iter5-check` removido do container.

### O que não deu para verificar

- Entrega real de e-mail (SMTP/sendmail do host): só o caminho até `wp_mail()` foi exercitado.
- Disco realmente cheio e timeout real de 30 s numa requisição Apache: as falhas foram simuladas via cache/`ini_set`.
- Backup completo usando os três limites + escrita atômica juntos: depende da ligação no handler, que não é meu arquivo.
- Multisite (o `information_schema` soma todas as tabelas do schema), views/triggers e PHP 7.4 (o código novo não usa sintaxe PHP 8, mas o teste usa `str_contains`, como os testes existentes).

## Como integrar

### 1. `src/failsafe.php` — adicionar os requires

Depois de `require_once FAILSAFE_DIR . '/includes/storage.php';` (a ordem não importa em runtime, mas `limits` usa `failsafe_backup_dir()`):

```php
require_once FAILSAFE_DIR . '/includes/limits.php';
require_once FAILSAFE_DIR . '/includes/notifications.php';
```

### 2. `src/admin/actions.php` — ligar limites, e-mail e escrita atômica

Renomeie a função atual `failsafe_create_backup()` para `failsafe_build_backup_files()` (corpo idêntico, exceto o `file_put_contents` abaixo) e coloque no lugar:

```php
/**
 * Creates the SQL dump and the ZIP archive.
 *
 * @return array|WP_Error Paths and sizes on success, WP_Error on failure.
 */
function failsafe_create_backup() {
	$limits = failsafe_check_limits();

	if ( is_wp_error( $limits ) ) {
		failsafe_send_status_email( $limits );

		return $limits;
	}

	$started = microtime( true );
	$result  = failsafe_build_backup_files();

	failsafe_send_status_email( $result, microtime( true ) - $started );

	return $result;
}
```

No corpo de `failsafe_build_backup_files()`, troque:

```php
	$sql_path = FAILSAFE_BACKUP_DIR . '/failsafe-backup.sql';
	$bytes    = file_put_contents( $sql_path, $sql );

	if ( false === $bytes ) {
		return new WP_Error(
			'failsafe_write_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not write the dump to %s.', 'failsafe' ),
				$sql_path
			)
		);
	}
```

por:

```php
	$sql_path = FAILSAFE_BACKUP_DIR . '/failsafe-backup.sql';
	$bytes    = failsafe_write_file_atomic( $sql_path, $sql );

	if ( is_wp_error( $bytes ) ) {
		return $bytes;
	}
```

Nada mais muda: `failsafe_write_file_atomic()` grava `failsafe-backup.sql.part` e renomeia para `failsafe-backup.sql`, então a janela de arquivo meio escrito some, e o ZIP continua usando o caminho final. `failsafe_handle_run_backup()` e `failsafe_create_zip()` não precisam de alteração.

Observação: com essa ligação, o handler atual mostra a mensagem genérica de `wp_die()`; o motivo específico vai no log e no e-mail. Se quiser mostrar o motivo na tela, use `$result->get_error_message()` no `wp_die()` (fora do meu escopo).

### 3. Opção do e-mail de sucesso (tela de configurações da iteração 4)

Adicionar em `src/admin/settings.php` um checkbox com `register_setting()` e sanitização booleana:

```php
<input type="checkbox" name="failsafe_email_on_success" value="1" <?php checked( get_option( 'failsafe_email_on_success', false ) ); ?> />
```

Sem a opção, o comportamento é "só falhas" (padrão seguro).

### 4. `src/uninstall.php`

Apagar também:

```php
delete_option( 'failsafe_email_on_success' );
delete_option( 'failsafe_db_size_cache' );
```

### 5. `tests/run-tests.sh` (arquivo de outro agente)

- Incluir `src/includes/*.php` no lint (hoje só `failsafe.php` e `admin/*.php`).
- Incluir `docker exec -u www-data "$WP" php "$TESTS_REMOTE/test-limits.php"` na etapa "Unit tests". O teste também roda direto no host (`php tests/test-limits.php`) porque carrega só as funções puras.
- Se o container ainda não tiver o plugin com `limits.php`, o teste avisa e sai com código 1; depois do merge ele passa a achar via `wp-load.php`.

### 6. Depois de restaurar um backup (iteração 3)

Chamar `failsafe_clear_database_size_cache()` ao final de uma restauração bem-sucedida, para o próximo backup medir o banco novo em vez de usar o cache antigo.

## Arquivos que o agente principal precisa tocar

- `src/failsafe.php` (requires).
- `src/admin/actions.php` (wrapper + escrita atômica).
- `src/admin/settings.php` (checkbox da option).
- `src/uninstall.php` (duas options).
- `tests/run-tests.sh` (lint dos includes + novo teste).
