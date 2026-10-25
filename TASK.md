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

# Tarefa: Iteração 2 — lista de backups, download e exclusão

Leia `DESIGN.md` (design aprovado, com decisões e assinaturas) e `src/includes/storage.php` (API pronta de armazenamento).

## Arquivos que você PODE criar/alterar
- `src/admin/menu.php` (novo) — `add_menu_page` slug `failsafe-backups`, ícone `dashicons-backup`, guarda o hook suffix, enfileira `assets/admin.js` só nessa tela.
- `src/admin/backups-page.php` (novo) — render da página: capability, avisos, tabela, estado vazio, total em disco.
- `src/admin/download.php` (novo) — `admin_post_failsafe_download_backup`: valida nome/tipo, `nocache_headers()`, `Content-Disposition`, `readfile()`, `exit`.
- `src/admin/delete.php` (novo) — `admin_post_failsafe_delete_backup`: POST, nonce por backup, `failsafe_delete_backup()`, redirect com aviso.
- `src/admin/format.php` (novo) — `failsafe_format_time()`, `failsafe_format_size()`.
- `src/admin/partials.php` (novo) — botão de backup e ações de linha reutilizáveis.
- `assets/admin.js` (novo) — confirmação de exclusão via `data-confirm`.
- `src/admin/actions.php` (alterar) — nome base com data (`wp_date('Ymd-His')`, colisão com sufixo `-2`..`-9`), gravar `.sql` e `.zip` com esse nome, chamar `failsafe_apply_retention( $base )` após sucesso, redirecionar para a página da lista.
- `src/admin/dashboard.php` (alterar) — widget vira resumo: último backup, total em disco, link para a página, botão.
- `src/failsafe.php` (alterar) — `require_once` dos arquivos novos.

## Requisitos
1. Nome base: `failsafe-YYYYMMDD-HHMMSS`, fuso do site (`wp_date`). Dois arquivos por conjunto.
2. Download e exclusão nunca aceitam caminho: só nome base validado por `failsafe_is_valid_backup_name()`.
3. Nonce de exclusão por arquivo: `failsafe_delete_backup_<base>`.
4. Tabela com data, arquivos, tamanho e ações; conjunto incompleto mostra selo.
5. Nenhum cache da listagem (`clearstatcache` já está em storage.php).
6. Zero `wp_die()` cru: erro vira aviso na página.

## Critério de pronto
- `php -l` limpo em todos os arquivos.
- Nenhum caminho vindo do request sem passar por `failsafe_is_valid_backup_name()`.
- Nenhuma saída sem escaping.
