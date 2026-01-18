# Upmath LaTeX Renderer (Chinese Version)

Service for generating nice **SVG pictures from LaTeX equations** for web.

- Online demo: https://xumin.net
- Upstream project: https://github.com/parpalak/i.upmath.me
- This fork: https://github.com/X2M7/i.upmath.me

---

## Run with Docker

### Pull from GHCR (recommended)

The Docker image is published to GitHub Container Registry.

Run:

```bash
docker run --rm -t -p 8080:80 ghcr.io/x2m7/i.upmath.me:latest
```

Then open:

- http://localhost:8080

Optional: run in background

```bash
docker run -d --name upmath -p 8080:80 ghcr.io/x2m7/i.upmath.me:latest
```

Stop and remove:

```bash
docker stop upmath
docker rm upmath
```

> Notes  
> - If the package is **Public**, you don’t need to login.  
> - If it is **Private**, login first:
>
> ```bash
> docker login ghcr.io
> ```

### Build locally (optional)

```bash
git clone https://github.com/X2M7/i.upmath.me.git
cd i.upmath.me
docker build -t i-upmath-me:local .
docker run --rm -p 8080:80 i-upmath-me:local
```

---

## Manual installation

### Requirements

1. [TeX Live](https://www.tug.org/texlive/quickinstall.html) (recommended full installation).
2. `nginx` web server with [ngx_http_lua_module](https://github.com/openresty/lua-nginx-module) (Debian example: `nginx-extras`).
3. `php-fpm`. `proc_open()` must be enabled.
4. Node.js and frontend tools: `npm`, `yarn`, `grunt-cli`.
5. `ghostscript` (used internally by `dvisvgm` TeX component).
6. Optional utilities for PNG: `rsvg-convert`, `optipng`, `pngout`. Install them or disable PNG support in code.

### Installation steps

Deploy files:

```bash
git clone https://github.com/X2M7/i.upmath.me.git
cd i.upmath.me
yarn install
composer install
grunt
```

Create the site config file:

```bash
cp config.php.dist config.php
nano config.php # specify TeX bin dir and other paths
```

Set up nginx:

```bash
sudo cp nginx.conf.dist /etc/nginx/sites-available/i.upmath.me
sudo nano /etc/nginx/sites-available/i.upmath.me
```

Enable the site and reload nginx (commands depend on distro; example):

```bash
sudo ln -s /etc/nginx/sites-available/i.upmath.me /etc/nginx/sites-enabled/i.upmath.me
sudo nginx -t
sudo systemctl reload nginx
```

Set up systemd unit for SVGO HTTP service:

```bash
sudo cp http-svgo.service.dist /etc/systemd/system/http-svgo.service
sed -i "s~@@DIR@@~$PWD~g" /etc/systemd/system/http-svgo.service
sudo systemctl start http-svgo
sudo systemctl enable http-svgo
```

---

## New features (this fork)

### 1) Chinese (CJK) support with pdfLaTeX

To support Chinese text in formulas while keeping `pdfLaTeX`, `document.php` was updated to include:

- `\usepackage[utf8]{inputenc}`
- `\usepackage{CJKutf8}`

And wrap document content with:

- `\begin{CJK}{UTF8}{gbsn}`
- `\end{CJK}`

You can add more LaTeX packages by appending additional `\usepackage{...}` lines in `document.php`.

### 2) Docker image includes CJK fonts/packages

The Docker build installs (for Chinese font support):

- `fonts-noto-cjk`
- `latex-cjk-all`

### 3) Optional: SVG recolor for dark mode

An optional solution is included to recolor SVG output only when a query parameter is provided:

- `?c=RRGGBB` or `?color=RRGGBB`

Default output stays unchanged. Cache keys are updated to prevent mixing outputs of different variants.
