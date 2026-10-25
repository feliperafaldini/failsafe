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

# Tarefa: Iteração 5 — limites, e-mail de status e escrita atômica

## Arquivos que você PODE criar/alterar
- `src/includes/limits.php` (novo) — verificação de limites antes do backup.
- `src/includes/notifications.php` (novo) — e-mail de status.
- `tests/test-limits.php` (novo) — testes puros (sem banco) das funções de cálculo.

NÃO altere `actions.php`, `storage.php`, `failsafe.php`.

## Requisitos
1. `failsafe_check_limits(): true|WP_Error`:
   - `memory_limit` (parse de `128M`/`-1`) contra a soma de `DATA_LENGTH + INDEX_LENGTH` das tabelas (information_schema via `$wpdb`), com fator de segurança 3x;
   - `disk_free_space( failsafe_backup_dir() )` contra o mesmo tamanho estimado;
   - `max_execution_time` e tempo estimado;
   - devolve `WP_Error` com código específico e mensagem que o usuário entenda.
2. `failsafe_estimate_database_size(): int` — tabelas do banco atual, cacheado em option por 10 minutos.
3. `failsafe_send_status_email( $result ): bool` — usa `wp_mail()` para `get_option('admin_email')`; envia em falha sempre, em sucesso só se `get_option('failsafe_email_on_success')`; texto em inglês, com tamanho, duração e erro.
4. Escrita atômica: usar `failsafe_write_file_atomic()` (já existe em storage.php) — **não** reimplemente.
5. Deixe em REPORT.md o trecho exato para eu ligar as funções no handler (`failsafe_create_backup`).

## Critério de pronto
- `php -l` limpo; `tests/test-limits.php` cobre parse de `memory_limit` (`128M`, `1G`, `-1`, `0`) e o fator de segurança.
