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

# Tarefa: Iteração 4 — agendamento (WP-Cron) e configurações

## Arquivos que você PODE criar/alterar
- `src/includes/cron.php` (novo) — agendamento, execução, lock e status.
- `src/admin/settings.php` (novo) — página de configurações (Settings API).
- `src/failsafe.php` (alterar apenas para adicionar os dois `require_once`).

NÃO altere `actions.php`, `dashboard.php`, `storage.php`.

## Requisitos
1. Frequências: hourly, twicedaily, daily + frequência customizada via `cron_schedules` (ex.: `failsafe_weekly`).
2. `register_activation_hook` agenda; `register_deactivation_hook` limpa. Ambos registrados em `src/includes/cron.php` apontando para `dirname( __DIR__ ) . '/failsafe.php'`.
3. Ao rodar: lock com `get_transient`/`add_option` para impedir duas execuções simultâneas; chama `failsafe_create_backup()` (já existe); grava `failsafe_last_run` (timestamp) e `failsafe_last_result` (ok/erro + código) em options; libera o lock em `finally`.
4. WP-Cron só roda com tráfego: mostrar na tela "última execução" e avisar quando atrasado (`wp_next_scheduled` no passado).
5. Configurações: ativar/desativar agendamento, frequência, retenção (`failsafe_retention_count`, 0–50). Settings API com `register_setting`, `sanitize_callback`, nonce e capability.
6. Nunca agendar duas vezes o mesmo evento (checar `wp_next_scheduled`).

## Critério de pronto
- `php -l` limpo.
- Nenhum evento duplicado possível; nenhum lock preso em caso de exceção.
- REPORT.md explica como testar sem tráfego (rodar `wp-cron.php` via CLI) e como o agente principal integra.
