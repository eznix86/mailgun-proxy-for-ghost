FROM golang:1.24-alpine AS hivemind
WORKDIR /src
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go install github.com/DarthSim/hivemind@v1.1.0

FROM php:8.5-fpm-alpine AS phpbase

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --no-cache \
        nginx \
    && install-php-extensions curl intl mbstring xml zip sqlite3 pdo_mysql pdo_pgsql pgsql opcache exif \
    && rm -rf /tmp/* /var/cache/apk/* /usr/local/bin/install-php-extensions

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM phpbase AS deps

RUN apk add --no-cache git unzip

WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    COMPOSER_CACHE_DIR=/root/.composer/cache \
    composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

FROM deps AS frontend

COPY --from=oven/bun:1-alpine /usr/local/bin/bun /usr/local/bin/bun

COPY package.json bun.lock ./
RUN --mount=type=cache,target=/root/.bun/install/cache bun install --frozen-lockfile

COPY . .

RUN bun run build

FROM deps AS build


COPY . .
COPY --from=frontend /app/public/build /app/public/build

RUN rm -f /app/bootstrap/cache/*.php \
    && composer dump-autoload --optimize --no-dev \
    && rm -rf /root/.composer/cache

FROM phpbase AS final

RUN apk add --no-cache shadow \
    && groupmod -g 82 www-data \
    && usermod -u 82 -g 82 www-data \
    && rm -rf /var/cache/apk/*

COPY --from=hivemind /go/bin/hivemind /usr/local/bin/hivemind

COPY docker/fpm/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/fpm/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/fpm/php-opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/fpm/nginx.conf /etc/nginx/nginx.conf
COPY docker/fpm/Procfile /etc/Procfile
COPY docker/fpm/entrypoint-fpm.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /app/app /var/www/html/app
COPY --from=build --chown=www-data:www-data /app/bootstrap /var/www/html/bootstrap
COPY --from=build --chown=www-data:www-data /app/config /var/www/html/config
COPY --from=build --chown=www-data:www-data /app/database /var/www/html/database
COPY --from=build --chown=www-data:www-data /app/public /var/www/html/public
COPY --from=build --chown=www-data:www-data /app/resources /var/www/html/resources
COPY --from=build --chown=www-data:www-data /app/routes /var/www/html/routes
COPY --from=build --chown=www-data:www-data /app/storage /var/www/html/storage
COPY --from=build --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --from=build --chown=www-data:www-data /app/artisan /var/www/html/artisan
COPY --from=build --chown=www-data:www-data /app/composer.json /var/www/html/composer.json
COPY --from=build --chown=www-data:www-data /app/composer.lock /var/www/html/composer.lock

RUN mkdir -p \
        /data/sessions \
        /data/uploads \
        /var/lib/nginx/logs \
        /var/lib/nginx/body \
        /var/www/html/storage/app/private \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data \
        /data \
        /var/lib/nginx \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && chmod -R 775 \
        /data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && rm -f /var/www/html/public/index.nginx-debian.html 2>/dev/null || true \
    && rm -rf /usr/share/nginx/html/* /var/cache/apk/* /tmp/*

USER 82
EXPOSE 8080

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite

CMD ["/usr/local/bin/hivemind", "/etc/Procfile"]
