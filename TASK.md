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

# Tarefa: documentação para o diretório do WordPress.org

## Arquivos que você PODE criar/alterar
- `src/readme.txt` (novo) — formato oficial do WordPress.org.
- `README.md` (alterar) — visão do repositório.
- `CHANGELOG.md` (novo).

NÃO altere código PHP.

## Requisitos
1. `readme.txt` com cabeçalho completo: `Contributors`, `Tags`, `Requires at least: 6.2`, `Tested up to`, `Requires PHP: 7.4`, `Stable tag: 0.1.0`, `License: GPLv2 or later`, `License URI`.
2. Seções: Description, Installation, Frequently Asked Questions, Screenshots (descrição sem imagem), Changelog, Upgrade Notice.
3. **Documente apenas o que existe hoje**: botão de backup que gera `.zip` (arquivos + `.sql`), diretório protegido, desinstalação limpa. Restauração, agendamento e lista de backups estão em desenvolvimento — declare isso na seção "Roadmap", sem prometer versão.
4. **Limites honestos** (obrigatório): tamanho de banco testado, aviso de que o backup roda na requisição do admin (timeout em sites grandes), dependência de `.htaccess` (nginx precisa de regra própria), multisite não suportado, `ZipArchive` obrigatório.
5. Texto em inglês (o diretório exige), tom direto, sem marketing exagerado.
6. Não invente URL de plugin, não invente autor, não invente screenshots.
