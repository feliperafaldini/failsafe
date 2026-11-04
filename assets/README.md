# Imagens do diretório do WordPress.org

Esta pasta documenta os arquivos de imagem da página do plugin em wordpress.org. As imagens vão para a pasta **`assets/` do SVN**, no nível superior, ao lado de `trunk/` e `tags/`. Elas **não** entram no ZIP do plugin, não ficam em `trunk/` e não devem ser versionadas neste repositório de código.

**Situação: as nove imagens existem.** Ícone e banner foram desenhados em SVG e rasterizados; os cinco screenshots estão em `assets/screenshots/`. Os arquivos-fonte (`icon.svg`, `banner.svg`) ficam em `assets/src/` e servem para regerar ou ajustar o nome sem refazer o desenho. Nenhuma imagem entra no ZIP do plugin: este `README.md` e a pasta `src/` são documentação e fonte, não assets do SVN.

## 1. Arquivos exigidos

| Arquivo | Dimensão | Formato | Limite | Estado |
|---|---|---|---|---|
| `icon-128x128.png` | 128 × 128 px | PNG | 1 MB | existe (520 B) |
| `icon-256x256.png` | 256 × 256 px | PNG | 1 MB | existe (928 B) |
| `banner-772x250.png` | 772 × 250 px | PNG | 4 MB | existe (10 KB) |
| `banner-1544x500.png` | 1544 × 500 px | PNG | 4 MB | existe (21 KB) |
| `screenshot-1.png` | 1280 × 720 px (capturado) | PNG | 10 MB | existe |
| `screenshot-2.png` | 1280 × 720 px (capturado) | PNG | 10 MB | existe |
| `screenshot-3.png` | 1280 × 720 px (capturado) | PNG | 10 MB | existe |
| `screenshot-4.png` | 1280 × 720 px (capturado) | PNG | 10 MB | existe |
| `screenshot-5.png` | 1280 × 720 px (capturado) | PNG | 10 MB | existe |

Observações:

- O ícone `256x256` é a versão de alta densidade (retina) e só funciona junto com o `128x128`; o banner `1544x500` é a versão retina do `772x250`.
- O diretório não define dimensão obrigatória para screenshots. O recomendado aqui é 1280 × 720 px, com todas as imagens no mesmo tamanho e proporção.
- Os nomes são minúsculos e exatos; nome fora do padrão não aparece na página do plugin.
- Depois de copiar para o SVN, rodar `svn propset svn:mime-type image/png *.png` para o navegador exibir a imagem em vez de baixá-la.

## 2. O que cada screenshot deve mostrar

A ordem abaixo é a que deve ser usada nos arquivos e nas legendas do `src/readme.txt`. O diretório associa `screenshot-1.png` à primeira linha da seção `== Screenshots ==`, `screenshot-2.png` à segunda, e assim por diante.

### `screenshot-1.png` — lista de backups

Tela **Failsafe > Backups** (`wp-admin/admin.php?page=failsafe-backups`), com o site já contendo pelo menos dois backups completos.

Deve mostrar:

- O título **Backups** e o botão **Backup now**.
- A tabela com as colunas **Date**, **Files**, **Size** e **Actions**.
- Em cada conjunto, os dois nomes reais (`failsafe-YYYYMMDD-HHMMSS.zip` e `failsafe-YYYYMMDD-HHMMSS.sql`), o tamanho e os botões **Download (.zip)**, **Download (.sql)** e **Delete**.
- O rodapé com a quantidade de backups e o total em disco (ex.: “2 backups, 24.3 MB on disk.”).
- Nenhum aviso de erro e nenhum conjunto marcado como **Incomplete**.

### `screenshot-2.png` — tela de restaurar

Tela **Ferramentas > Failsafe Restore** (`wp-admin/tools.php?page=failsafe-restore`).

Deve mostrar:

- O aviso amarelo “Restoring replaces every table contained in the backup. A safety backup is created first, but the current database will be overwritten. Site files are not changed. Keep this tab open until the restore finishes.”
- A tabela com as colunas **Backup**, **Date**, **Size** e **Restore**.
- Um conjunto com ZIP válido, exibindo o nome em `<code>`, o campo **Type the backup name to confirm:** e o botão **Restore database**.
- Nenhum aviso de erro ou de restauração concluída.

### `screenshot-3.png` — configurações

Tela **Configurações > Failsafe Backup** (`wp-admin/options-general.php?page=failsafe-settings`).

Deve mostrar:

- A tabela de status com **Automatic backups** (`Enabled`), **Last run**, **Last result** e **Next run**.
- A seção **Automatic backups**, com o checkbox **Schedule** marcado e o seletor **Frequency** (Hourly, Twice daily, Daily, Weekly).
- A seção **Storage**, com o campo **Backups to keep** preenchido com `5`.
- O botão **Save Changes**.
- Sem avisos de atraso, de evento ausente ou de `DISABLE_WP_CRON`; esses estados ficam para outra imagem, se necessário.

O texto auxiliar do campo de retenção deve aparecer como “How many backup sets to keep. Use 0 to keep every backup.”

### `screenshot-4.png` — aviso de sucesso

A mesma tela **Failsafe > Backups**, imediatamente depois de clicar em **Backup now**.

Deve mostrar:

- O aviso verde e dispensável **“Backup created.”** acima da tabela.
- O conjunto recém-criado no topo da lista, com os dois arquivos e o tamanho.
- O botão **Backup now** e o rodapé com a contagem atualizada.
- A tabela visível junto com o aviso, para deixar claro que o backup apareceu na lista.

### `screenshot-5.png` — mensagem de limite excedido

Estado atual (0.2.0): o aviso da checagem prévia chega à tela. `failsafe_handle_run_backup()` redireciona com o código do erro e a página **Backups** traduz os códigos conhecidos em mensagens específicas (`src/admin/backups-page.php`), por exemplo:

- memória: “This site has about … of database. Dumping it needs about … of memory …, but PHP only allows …”;
- disco: “The backup directory … needs about … of free space, but only … is available.”;
- tempo: “The backup was not created: the expected run time exceeds the PHP execution limit. Raise max_execution_time or run the backup from WP-Cron.”

A captura que está em `assets/screenshots/screenshot-5.png` é a do limite de tempo. Não simular a mensagem em HTML ou editar a captura: a imagem tem que corresponder ao que o plugin realmente mostra.

## 3. Como capturar

- Use o site local em `http://localhost:8080` (WordPress 7.1.3), com pelo menos dois backups reais e sem dados pessoais.
- Janela do navegador em 1280 × 720 ou maior, zoom em 100%. Recorte apenas a área do WordPress, sem barra de endereço, abas ou favoritos.
- Prefira o tema padrão do painel e o idioma inglês, para bater com as legendas do `readme.txt`.
- Preencha os nomes e tamanhos com dados reais do ambiente local; não invente números.
- Salve em PNG com os nomes exatos da tabela da seção 1.

## 4. Vínculo com o `readme.txt`

Hoje `src/readme.txt` tem **quatro** legendas em `== Screenshots ==` e a frase “No screenshot images are bundled with version 0.2.0. The entries below describe the current screens.” Antes de enviar as imagens:

- remover a frase que diz que não há imagens;
- reescrever a lista com **cinco** legendas, na ordem desta página (lista de backups, restaurar, configurações, sucesso, limite excedido);
- manter cada legenda curta e descritiva, porque ela vira a legenda da imagem na página do plugin.

Se o `screenshot-5.png` não for publicado, o `readme.txt` deve ficar com quatro legendas na ordem correspondente; nunca deixar uma legenda sem imagem ou uma imagem sem legenda.

## 5. Checklist dos assets

- [x] `icon-128x128.png` e `icon-256x256.png` em 128 × 128 e 256 × 256 px.
- [x] `banner-772x250.png` e `banner-1544x500.png` em 772 × 250 e 1544 × 500 px.
- [x] `screenshot-1.png` a `screenshot-5.png`, todos 1280 × 720, com o conteúdo descrito acima.
- [ ] Nomes exatos, tudo em minúsculas.
- [x] `readme.txt` com a mesma quantidade e ordem de legendas (5).
- [ ] Arquivos copiados para `~/failsafe-svn/assets/`, nunca para `trunk/`.
- [ ] `svn add`, `svn propset svn:mime-type image/png *.png` e commit separado dos assets.

## 6. Ícone e banner: desenho e como regerar

Os dois saem do mesmo desenho: dois colchetes contendo três barras — a lista de conjuntos datados dentro de algo que a delimita (o "recipiente protegido"). A barra de cima é o acento (`#9fcbf7`), as outras duas são neutras, porque só o conjunto mais recente é o "atual". Fundo `#111416`.

Cores: fundo `#111416`, acento `#9fcbf7`, neutros `#e2e2e6`, `#5b6774`, `#39424c`, colchetes `#454f5a` — os mesmos do tema do desktop, então o ícone não destoa do resto.

O SVG usa `viewBox` do tamanho final, então o retina é só renderizar em dobro; não há reescala de bitmap.

```sh
cd ~/Projects/failsafe/assets
rsvg-convert -w 128  -h 128  -o icon-128x128.png      src/icon.svg
rsvg-convert -w 256  -h 256  -o icon-256x256.png      src/icon.svg
rsvg-convert -w 772  -h 250  -o banner-772x250.png    src/banner.svg
rsvg-convert -w 1544 -h 500  -o banner-1544x500.png   src/banner.svg
```

Conferir margens e tamanho (as margens têm que ficar iguais nos dois lados):

```sh
magick banner-772x250.png -fuzz 3% -trim info:
magick icon-128x128.png -fuzz 3% -trim info:
```

Se o nome do plugin mudar, o banner muda em dois elementos `<text>` de `src/banner.svg` (`FAILSAFE` e `BACKUP`), e o `Text Domain`/slug mudam no código — o desenho não muda.
