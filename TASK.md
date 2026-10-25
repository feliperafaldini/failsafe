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

# Tarefa: testes de unidade da camada de armazenamento

## Arquivos que você PODE criar/alterar
- `tests/test-storage.php` (novo).
- `tests/run-tests.sh` (alterar apenas para incluir o novo teste).
- `tests/assert.php` (usar; pode estender se necessário).

NÃO altere nada em `src/`.

## Requisitos
Rodam dentro do container (`php` com `require '/var/www/html/wp-load.php'`), como os testes existentes.

1. `failsafe_is_valid_backup_name()`: aceita `failsafe-20261024-153000` e `failsafe-20261024-153000-2`; rejeita `../x`, `failsafe-2026-153000`, string vazia, caminho absoluto, nome com mais de 40 chars, `failsafe-20261024-153000.zip`.
2. `failsafe_backup_path()`: devolve caminho dentro do diretório; `WP_Error` para nome inválido e para extensão fora de zip/sql.
3. `failsafe_get_backups()`: cria arquivos temporários válidos no diretório de backups, confere agrupamento por conjunto, `complete`, ordenação decrescente e tamanho somado; remove tudo no fim (inclusive em falha — use `register_shutdown_function`).
4. `failsafe_apply_retention()`: com `failsafe_retention_count` = 2 e 4 conjuntos, apaga os 2 mais antigos, nunca o `$keep_base`; restaura a option original no fim.
5. `failsafe_write_file_atomic()`: grava conteúdo, não deixa `.part`, e devolve o número de bytes.

## Critério de pronto
- `php -l` limpo.
- Cada teste cria o próprio estado e limpa; nenhum teste depende de ordem.
- Nenhuma escrita fora de `wp-content/failsafe-backups` e do diretório temporário do PHP.
