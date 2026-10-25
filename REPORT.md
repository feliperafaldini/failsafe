# Relatório — documentação para o diretório do WordPress.org

## Arquivos

| Arquivo | Ação |
| --- | --- |
| `src/readme.txt` | criado — readme oficial do WordPress.org, em inglês |
| `README.md` | alterado — visão do repositório, em português |
| `CHANGELOG.md` | criado — histórico em português, no formato Keep a Changelog |

Nenhum arquivo PHP foi alterado.

## O que foi feito

### `src/readme.txt`

- Cabeçalho completo com `Contributors`, `Tags` (5), `Requires at least: 6.2`, `Tested up to: 7.1`, `Requires PHP: 7.4`, `Stable tag: 0.1.0`, `License: GPLv2 or later` e `License URI`.
- Descrição curta com 106 caracteres (limite do diretório: 150).
- Seções `Description`, `Installation`, `Frequently Asked Questions`, `Screenshots`, `Limitations`, `Roadmap`, `Changelog` e `Upgrade Notice`.
- Documenta somente o que o código de 0.1.0 faz hoje: widget **Backup now**, dump SQL, ZIP com arquivos + dump, diretório protegido e desinstalação limpa.
- Declara no `Roadmap` que restauração, agendamento e lista/download/exclusão de backups estão em desenvolvimento, sem versão ou data prometida.
- Limites honestos: banco testado (~2 MB, 12 tabelas), backup na requisição do admin (timeout), dependência de `.htaccess` (nginx precisa de regra própria), multisite não suportado, `ZipArchive` obrigatório, views/triggers/procedures/events fora do dump e ZIP sensível por conter `wp-config.php`.
- Não inclui URL de plugin, autor, screenshots ou arquivos de imagem inventados.

### `README.md`

- Reescrito como visão do repositório: estado atual, requisitos, estrutura de diretórios, ambiente Docker, testes, limitações, dívida conhecida, roadmap e licença.
- Mantido em português, conforme `AGENTS.md`, com a distinção de que apenas `src/` é publicado.
- Explicita que `tests/`, `stubs/`, `docker-compose.yml` e os arquivos de repositório não fazem parte do pacote do plugin.

### `CHANGELOG.md`

- Formato Keep a Changelog, em português, com `[Não publicado]` e `[0.1.0] - 2026-10-24`.
- Cobre apenas o que está no código: widget, dump, ZIP, proteção do diretório, logs, desinstalação, camada de armazenamento sem interface e testes.
- Registra segurança e limites conhecidos na mesma versão.

## Decisões

- **Idioma**: `src/readme.txt` em inglês, porque o diretório exige; `README.md` e `CHANGELOG.md` em português, seguindo `AGENTS.md`.
- **Escopo**: a camada `src/includes/storage.php` já existe, mas não tem tela. Por isso ela não foi vendida como funcionalidade no `readme.txt`; aparece apenas como dívida/roadmap no README e no changelog.
- **Somente o backup mais recente**: o código atual grava nomes fixos (`failsafe-backup.sql` e `failsafe-backup.zip`) e sobrescreve a execução anterior. Isso foi documentado na descrição e no FAQ em vez de prometer histórico.
- **Screenshots**: a seção foi escrita com descrições das telas reais (widget e aviso de sucesso), sem imagens, como pedido. O texto avisa que nenhuma imagem acompanha a 0.1.0.
- **Dados verificados no ambiente**: WordPress 7.1.3 no container, banco de 2,1 MB com 12 tabelas, `memory_limit` de 128 MB e extensão `zip` habilitada. O tempo de ~9 s veio do `AGENTS.md`/execução anterior, não foi medido nesta tarefa.

## O que não deu para verificar

- **Validador oficial do WordPress.org**: não está disponível offline. O cabeçalho e as seções foram conferidos com um parser próprio; a validação final precisa ser feita no envio.
- **Usuário do WordPress.org**: `felipealbino` é uma inferência a partir do nome do autor no cabeçalho do plugin, não um username confirmado. **Trocar pelo username real antes de publicar.**
- **Compatibilidade real com PHP 7.4**: o cabeçalho e o `readme.txt` declaram 7.4 (exigência da tarefa), mas `src/admin/actions.php` usa `str_starts_with()`, que exige PHP 8.0. O plugin não roda em 7.4 como está. Veja a integração abaixo.
- **nginx e multisite**: comportamento documentado a partir do código e do `AGENTS.md`; não foram testados nesta tarefa.
- **Screenshots reais**: não existem arquivos `screenshot-1.png`/`screenshot-2.png`; a seção é apenas descritiva.
- **Suíte completa** (`./tests/run-tests.sh`): não foi executada porque a tarefa é somente de documentação e nenhum PHP mudou. Rodei `php -l` nos arquivos criados/alterados (são texto, mas o comando passa) e nos PHP existentes, todos sem erro.

## Como integrar

1. O worktree está no branch `docs/readme`. Fazer merge/cherry-pick dos três arquivos; **não** houve commit.
2. Antes da submissão ao WordPress.org:
   - substituir `Contributors: felipealbino` pelo username real;
   - resolver a divergência de PHP: ou subir `Requires PHP` para 8.0 no cabeçalho **e** no `readme.txt`, ou trocar `str_starts_with()` por um fallback compatível com 7.4 (ex.: `0 === strpos( ... )`);
   - confirmar `Tested up to: 7.1` contra a versão estável mais recente do WordPress na data do envio;
   - adicionar screenshots reais ou remover a seção `Screenshots`, se o diretório exigir imagens.
3. Se a interface de lista/download/restauração for ligada antes da publicação, atualizar a descrição, o FAQ, as limitações e o roadmap do `readme.txt`, além do `CHANGELOG.md`.
4. `TASK.md` está não rastreado no worktree e não foi alterado por mim.
