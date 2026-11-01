FROM ghcr.io/parpalak/upmath-texlive-docker:2025.0.1 AS texlive

FROM debian:13-slim@sha256:f6b08b09a05725f9431502d3b5714f5c4576e82c1a84fa1debe0c24a7e0c3ec4

ARG PHP_MIN_VERSION=8.4.27-1~deb13u1

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

EXPOSE 80
STOPSIGNAL SIGQUIT
HEALTHCHECK --interval=30s --timeout=3s --start-period=30s --retries=3 \
    CMD ["php", "-r", "$s=@fsockopen('127.0.0.1',80,$errno,$errstr,2); if (!$s) { exit(1); } fwrite($s, \"GET /latex.js HTTP/1.0\\r\\nHost: localhost\\r\\n\\r\\n\"); $status=fgets($s); fclose($s); exit(is_string($status) && str_contains($status, ' 200 ') ? 0 : 1);"]

WORKDIR /var/www/i.upmath.me

COPY --from=texlive /usr/local/texlive /usr/local/texlive

ARG DEBIAN_FRONTEND=noninteractive

# Install current Debian security updates; exact pinning would prevent future fixes.
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
    ca-certificates \
    fonts-noto-cjk \
    nginx libnginx-mod-http-lua libnginx-mod-http-ndk \
    zip unzip \
    php8.4-fpm \
    php8.4-curl \
    php8.4-xml \
    php8.4-gd \
    composer \
    ghostscript \
    imagemagick \
    librsvg2-bin \
    optipng \
    supervisor \
    curl gnupg \
    && php_version="$(dpkg-query -W -f='${Version}' php8.4-fpm)" \
    && if ! dpkg --compare-versions "$php_version" ge "$PHP_MIN_VERSION"; then echo "PHP package $php_version is older than required $PHP_MIN_VERSION" >&2; exit 1; fi \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && node -e "if (Number(process.versions.node.split('.')[0]) !== 24) process.exit(1)" \
    && apt-get remove -y curl gnupg \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/

COPY . .

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    && composer audit --locked --no-dev \
    && apt-get purge -y composer zip unzip \
    && apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/

RUN npm install -g yarn@1.22.22 \
    && yarn install --frozen-lockfile --non-interactive \
    && yarn build \
    && rm -rf node_modules \
    && yarn install --production=true --frozen-lockfile --non-interactive \
    && yarn cache clean \
    && npm uninstall -g yarn \
    && rm -rf /usr/lib/node_modules/npm /usr/bin/npm /usr/bin/npx

RUN mkdir -p logs tex_logs tmp www/_cache www/_error /var/run/php-fpm \
    && chown -R www-data:www-data logs tex_logs tmp www/_cache www/_error /var/run/php-fpm \
    && chmod 0750 logs tex_logs tmp www/_cache www/_error /var/run/php-fpm

RUN cp config.php.dist config.php  \
    && tlversion=$(cat /usr/local/texlive/20*/release-texlive.txt | head -n 1 | awk '{ print $5 }') \
    && sed -i "s/\${tlversion}/${tlversion}/g" config.php
RUN cp docker/nginx.conf /etc/nginx/nginx.conf \
    && nginx -t -c /etc/nginx/nginx.conf
RUN cp docker/www.conf /etc/php/8.4/fpm/pool.d/www.conf \
    && cp docker/www-tex.conf /etc/php/8.4/fpm/pool.d/www-tex.conf \
    && php-fpm8.4 -t --fpm-config /etc/php/8.4/fpm/pool.d/www.conf \
    && php-fpm8.4 -t --fpm-config /etc/php/8.4/fpm/pool.d/www-tex.conf

RUN cp docker/superv.conf /etc/superv.conf

ENTRYPOINT [ "/var/www/i.upmath.me/docker/entrypoint.sh" ]
