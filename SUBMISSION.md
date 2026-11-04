# Submissão ao diretório do WordPress.org — Failsafe Backup 0.2.0

- Slug: `failsafe-backup`
- Versão / `Stable tag`: `0.2.0`
- SVN: `https://plugins.svn.wordpress.org/failsafe-backup`
- Repositório local: `~/Projects/failsafe`
- Estado: **não publicado**. Este documento é uma lista de conferência; nada aqui publica o plugin.

## 1. Checklist

### 1.1 O que já está pronto

- [x] **`src/readme.txt`** — readme do diretório em inglês, com descrição, instalação, FAQ, limitações, changelog e `Upgrade Notice`. Declara `Requires at least: 6.2`, `Requires PHP: 8.0`, `Stable tag: 0.2.0` e licença GPLv2 ou superior.
- [x] **`src/languages/failsafe-backup.pot`** — template de tradução do text domain `failsafe-backup`, gerado pelo WP-CLI 2.12.0. O `Domain Path: /languages` já está no cabeçalho de `src/failsafe.php`. Contém as strings do commit `3f3383e`; precisa ser regerado se o código final tiver strings novas (ver 2.2).
- [x] **`bin/build.sh`** — gera os dois pacotes a partir de `src/`, respeitando `.distignore`, e lê a versão do cabeçalho do plugin. Exige a extensão PHP `zip` (`ZipArchive`).
- [x] **`dist/failsafe-backup.zip`** — layout de `trunk/`, arquivos na raiz: 22 arquivos, 57.241 bytes no build atual.
- [x] **`dist/failsafe-backup-0.2.0.zip`** — layout de instalador, tudo dentro de `failsafe-backup/`: 22 arquivos, 57.945 bytes no build atual. É este que serve para o formulário de submissão e para **Plugins > Add New > Upload Plugin**.
- [x] **Conteúdo do pacote** — `failsafe.php`, `readme.txt`, `uninstall.php`, `admin/`, `includes/`, `assets/admin.js` e `languages/failsafe-backup.pot`. **Não é versionado**: `dist/` está no `.gitignore`. Regerar no commit final.
- [x] **`tests/` e `stubs/` fora do pacote** — o build empacota apenas `src/`, então os testes e os stubs não entram no ZIP.
- [x] **`assets/README.md`** — especifica os ícones, banners e os cinco screenshots do diretório, com dimensões e conteúdo. As imagens ainda não existem.
- [x] **Cabeçalho do plugin** — `src/failsafe.php` declara `Version: 0.2.0`, `Requires at least: 6.2`, `Requires PHP: 8.0`, `Text Domain: failsafe-backup` e `Domain Path: /languages`.

### 1.2 O que falta e bloqueia o envio

- [x] **`Contributors` confirmado** — `src/readme.txt` traz `Contributors: feliperafaldini`, conta criada em 2026-10-25 e verificada em `https://profiles.wordpress.org/feliperafaldini/` (HTTP 200). É o mesmo login que precisa ter acesso de commit ao SVN.
- [x] **`Tested up to` confirmado** — `src/readme.txt` traz `Tested up to: 7.1`. Em 2026-10-25, `https://api.wordpress.org/core/version-check/1.7/` devolve **7.1.3** como versão atual do WordPress e o container roda a mesma 7.1.3 (`wp core version`). 7.1 é a série mais alta lançada, então o valor declarado corresponde ao que foi testado.
- [x] **Screenshots capturados** — `assets/screenshots/` tem `screenshot-1.png` a `screenshot-5.png`, todos 1280 × 720 (conferido em 2026-10-25 lendo o cabeçalho PNG), e o `== Screenshots ==` de `src/readme.txt` já tem 5 legendas na mesma ordem dos arquivos. Faltam só `icon-*` e `banner-*`, que não bloqueiam a submissão (ver `assets/README.md`).
- [x] **`plugin check` sem erros** — comando oficial no container:
  ```sh
  docker exec -u www-data failsafe-wordpress-1 php /tmp/wp-cli.phar --path=/var/www/html plugin check failsafe-backup --format=table
  ```
  Execução de 2026-10-25 contra o HEAD `77ba040`: **`Success: Checks complete. No errors found.`** Reexecutar no commit que for para o trunk.
- [x] **ZIP do formulário de submissão** — `./bin/build.sh` agora gera `dist/failsafe-backup-0.2.0.zip` com a pasta `failsafe-backup/` na raiz, que é o formato aceito pelo formulário e pelo instalador.
- [ ] **Submeter o plugin e aguardar a criação do repositório SVN** — em 2026-10-24, `https://plugins.svn.wordpress.org/failsafe-backup/` responde **404** (os repositórios de `akismet` e `hello-dolly` respondem 200). O checkout da seção 3 só funciona depois que o plugin for aprovado pelo diretório e o repositório SVN for criado; o primeiro envio é o formulário de submissão com o ZIP do plugin, não o `svn ci`.
- [ ] **Enviar por SVN** — trunk, tag `0.2.0` e assets, depois da aprovação. Roteiro na seção 3.
- [ ] **Confirmar a tag `0.2.0`** — `Stable tag: 0.2.0` só funciona depois que `tags/0.2.0` existir no SVN. A tag tem que ser criada a partir do mesmo trunk que foi commitado.

### 1.3 Ressalvas encontradas na conferência

Não impedem o `svn ci`, mas precisam de decisão antes de divulgar o pacote:

Nenhuma pendente. As três ressalvas da conferência anterior foram corrigidas na master:

- **`assets/admin.js`** passou para `src/assets/admin.js`, entra no pacote e responde 200 em `/wp-content/plugins/failsafe-backup/assets/admin.js`. A confirmação de exclusão volta a carregar.
- **Lock do backup manual** documentado no readme e no changelog; o aviso “Another backup is already running.” existe na tela.
- **Mensagem de limite** agora aparece na interface: a lista traduz os códigos de erro conhecidos (memória, disco, tempo, `ZipArchive` ausente, diretório não gravável, escrita falha, arquivo parcial, sem tabelas). O screenshot 5 pode ser capturado.

## 2. Regerar os artefatos

### 2.1 Pacote

Na raiz do repositório:

```sh
cd ~/Projects/failsafe
./bin/build.sh
```

Saída esperada: duas linhas com quantidade de arquivos e tamanho, e dois arquivos em `dist/`. Conferir os dois layouts:

```sh
unzip -l dist/failsafe-backup.zip | head -5
unzip -l dist/failsafe-backup-0.2.0.zip | head -5
```

No primeiro, o topo é `assets/`, `admin/` e `failsafe.php`. No segundo, tudo começa com `failsafe-backup/`.

O ZIP deve conter `failsafe.php` na raiz, `readme.txt`, `uninstall.php`, as pastas `admin/`, `includes/` e `languages/`, e **não** deve conter `tests/`, `stubs/`, `bin/`, `docker-compose.yml`, `README.md`, `CHANGELOG.md`, `TASK.md`, `AGENTS.md`, `SUBMISSION.md` nem `assets/README.md`.

Esse é o layout do `trunk/`. O formulário de submissão e o **Plugins > Add New > Upload Plugin** usam o outro arquivo, `dist/failsafe-backup-0.2.0.zip`, gerado pelo mesmo `./bin/build.sh`, com o primeiro nível `failsafe-backup/`.

### 2.2 Template de tradução (`.pot`)

O `src/languages/failsafe-backup.pot` foi regerado depois das últimas mudanças e está com 167 strings, incluindo as mensagens de erro específicas e o aviso de execução concorrente. Gere de novo no commit final; a comparação deve diferir apenas em `POT-Creation-Date` e `PO-Revision-Date`.

```sh
docker cp /tmp/wp-cli.phar failsafe-wordpress-1:/tmp/wp-cli.phar
docker exec -u www-data failsafe-wordpress-1 php /tmp/wp-cli.phar --path=/var/www/html i18n make-pot /var/www/html/wp-content/plugins/failsafe-backup /tmp/failsafe-backup.pot --domain=failsafe-backup
docker cp failsafe-wordpress-1:/tmp/failsafe-backup.pot src/languages/failsafe-backup.pot
```

O `--allow-root` é necessário porque o `docker exec` roda como root. O `--path` aponta para a instalação WordPress do container; o primeiro caminho é a raiz do plugin e o segundo é o arquivo de saída. Depois de gerar, conferir o diff e commitar o `.pot` junto com as strings novas.

## 3. Passo a passo do SVN

Estes comandos só funcionam depois que o plugin for aprovado e o repositório SVN existir (ver 1.2). O envio inicial é feito pelo formulário de submissão do WordPress.org, não por `svn ci`.

Os comandos usam o repositório em `~/Projects/failsafe` e um checkout em `~/failsafe-svn`. Ajuste apenas se o checkout já existir em outro lugar.

### 3.1 Checkout (primeira vez)

```sh
svn checkout https://plugins.svn.wordpress.org/failsafe-backup ~/failsafe-svn
```

Nas vezes seguintes:

```sh
svn update ~/failsafe-svn
```

### 3.2 Gerar o pacote e atualizar o `trunk`

```sh
cd ~/Projects/failsafe
./bin/build.sh

# Limpa o trunk e copia o conteúdo do ZIP para dentro dele.
find ~/failsafe-svn/trunk -mindepth 1 -delete
unzip -o dist/failsafe-backup.zip -d ~/failsafe-svn/trunk/

# Confere os arquivos que serão enviados.
find ~/failsafe-svn/trunk -type f | sort
```

### 3.3 Marcar arquivos novos e removidos

```sh
cd ~/failsafe-svn
svn add --force trunk
svn status trunk | sed -n 's/^! *//p' | xargs -r svn rm
svn status
```

Antes do commit, `svn status` deve mostrar apenas linhas `A` (novos) e `M`/`D` (alterados/removidos). Nenhum `?` deve sobrar dentro de `trunk/`.

### 3.4 Commit do trunk

```sh
cd ~/failsafe-svn
svn ci -m "Failsafe Backup 0.2.0"
```

O SVN pede o usuário e a senha da conta WordPress.org. Não grave a senha em script, histórico de shell compartilhado ou arquivo do repositório.

### 3.5 Criar a tag

```sh
svn copy https://plugins.svn.wordpress.org/failsafe-backup/trunk \
         https://plugins.svn.wordpress.org/failsafe-backup/tags/0.2.0 \
         -m "Tag version 0.2.0"
```

Conferir:

```sh
svn ls https://plugins.svn.wordpress.org/failsafe-backup/tags/0.2.0/
```

A tag precisa ser criada **depois** do commit do trunk e o `Stable tag` do `readme.txt` dentro dela precisa continuar `0.2.0`.

### 3.6 Enviar as imagens para `assets/`

As imagens ficam em `assets/` no nível superior do SVN, ao lado de `trunk/` e `tags/`; não vão para o trunk nem para o ZIP. Depois de gerar os arquivos listados em `assets/README.md`, a partir da pasta onde eles estão:

```sh
cp icon-128x128.png icon-256x256.png banner-772x250.png banner-1544x500.png \
   screenshot-1.png screenshot-2.png screenshot-3.png screenshot-4.png screenshot-5.png \
   ~/failsafe-svn/assets/

cd ~/failsafe-svn/assets
svn add --force .
svn propset svn:mime-type image/png *.png
svn ci -m "Add plugin directory assets"
```

O `svn propset svn:mime-type image/png` evita que o navegador baixe o PNG em vez de exibi-lo na página do plugin.

### 3.7 Conferência final

```sh
svn ls https://plugins.svn.wordpress.org/failsafe-backup/
svn ls https://plugins.svn.wordpress.org/failsafe-backup/trunk/
svn ls https://plugins.svn.wordpress.org/failsafe-backup/assets/
```

Conferir também a página `https://wordpress.org/plugins/failsafe-backup/` depois da propagação. O diretório pode levar de alguns minutos a algumas horas para atualizar.

## 4. O que NÃO fazer

- **Não commitar `dist/`.** O ZIP é artefato de build e já está no `.gitignore`.
- **Não enviar `tests/`, `stubs/`, `bin/`, `docker-compose.yml`, `README.md`, `CHANGELOG.md`, `TASK.md`, `AGENTS.md`, `SUBMISSION.md` ou `assets/README.md`** para o SVN. Ao copiar o conteúdo de `dist/failsafe-backup.zip` para `trunk/`, nada disso é copiado.
- **Não colocar imagens em `trunk/` nem no ZIP.** Ícones, banners e screenshots vão apenas para `assets/` no SVN.
- **Não usar `dist/failsafe-backup.zip` no formulário de submissão.** Ele tem os arquivos na raiz (layout de `trunk/`); o envio inicial precisa da pasta `failsafe-backup/`.
- **Não colocar credenciais no repositório, em comandos versionados ou em log.** A senha do WordPress.org é digitada no prompt do SVN.
- **Não criar a tag antes do commit do trunk** e não reutilizar uma tag já publicada; para uma correção, publique outra versão.
- **Não mudar `Stable tag` sem criar a tag correspondente.**
- **Não publicar screenshot que não corresponda à tela real** nem usar dados de um site de produção com informação pessoal.
- **Não declarar `Tested up to` acima da versão realmente testada.**

## 5. Ordem sugerida

1. Revisar `Contributors` e `Tested up to` no `src/readme.txt` (depende de você).
2. Gerar os cinco screenshots seguindo `assets/README.md` e ajustar a seção `== Screenshots ==` se a ordem mudar.
3. Rodar `./bin/build.sh` e conferir os dois ZIPs.
4. Rodar `wp i18n make-pot` de novo se alguma string mudou desde o último commit.
5. Rodar o Plugin Check no commit final (exige `Success: Checks complete. No errors found.`).
6. Enviar o formulário de submissão com `dist/failsafe-backup-0.2.0.zip` e, depois da aprovação, enviar o trunk, criar a tag `0.2.0` e enviar as imagens em `assets/`.
