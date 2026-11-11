# Upmath LaTeX Renderer (Chinese Version)

Service for generating nice **SVG pictures from LaTeX equations** for web.

- Online demo: https://tex.xumin.net
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

### Formula caching

Successful formula images use `Cache-Control: public, max-age=86400, s-maxage=15552000`
(one day in browsers, 180 days in shared caches). Formula errors and overload
responses use `no-store`. In EdgeOne, match the renderer host and URL prefixes
such as `/svg/*` and `/svgb/*`, not just a `.svg` suffix. Preserve the `c` and
`color` query parameters in the cache key, and do not override error responses
with a long status-code cache TTL.

Compressed formula URLs are resolved in the lightweight PHP pool. Nginx serves
cached images, including existing gzip-only SVGs, without entering the TeX
render queue. Only cache misses use the bounded queue and the two TeX workers.

Both Nginx templates include the SVG protections from upstream `ff8fea4`.
Direct navigation, image requests, and requests without `Sec-Fetch-Dest` receive
a CSP that blocks scripts. Legacy `object`, `embed`, and `iframe` requests retain
the inline baseline `postMessage` script, confined by `sandbox allow-scripts`
without `allow-same-origin`. Cache directories cannot be accessed externally.

Image responses include `Vary: Sec-Fetch-Dest` because their CSP differs by
embedding context. **Before deploying these templates behind EdgeOne, enable
the Vary feature for the renderer host** and ensure `Sec-Fetch-Dest` reaches the
origin. See [EdgeOne's Vary documentation](https://intl.cloud.tencent.com/zh/document/product/1145/54451).
An equivalent CDN configuration must vary its cache key on that request header,
including its absence; do not strip the existing `Accept-Encoding` variation.
After deployment, refresh the affected formula URLs so cached old response
headers do not remain active for the 180-day TTL. Verify direct-navigation and
embedded responses through the CDN as well as against the origin.

Keep generated images across container replacements with named volumes:

```bash
docker run -d --name upmath --restart unless-stopped \
  -p 127.0.0.1:8080:80 \
  --mount type=volume,src=upmath-cache,dst=/var/www/i.upmath.me/www/_cache \
  --mount type=volume,src=upmath-error-cache,dst=/var/www/i.upmath.me/www/_error \
  ghcr.io/x2m7/i.upmath.me:latest
```

Before replacing an existing container without these mounts, back up and migrate
its cache directories. Keep the previous container/image until the replacement
passes checks. Do not clear the entire CDN cache as part of a routine deployment.

The Docker workflow runs cache regressions before publishing. To repeat them in
an isolated test container (the fixtures modify its cache):

```bash
docker exec --user www-data upmath-test php test/cache-fixture.php
docker exec upmath-test node test/cache-smoke.mjs http://127.0.0.1
```

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
