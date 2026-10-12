# Production image for the NFC affiliate system.
#   target "app": PHP-FPM application (also used for the queue worker and scheduler)
#   target "web": nginx serving the built frontend and passing PHP requests to "app"

FROM node:22-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.* tsconfig*.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS vendor
# --prefer-source clones from git instead, for networks that block GitHub's zip downloads.
ARG COMPOSER_PREFER=--prefer-dist
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress $COMPOSER_PREFER
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.3-fpm-alpine AS app
# mysqldump for scripts/backup-db.sh. Set to 0 if backups run from another machine.
ARG INSTALL_MYSQL_CLIENT=1
USER root
# Adds only the extensions the base image lacks (pdo_mysql, zip for XLSX exports, pcntl for queue timeouts).
RUN set -eux; need=""; \
    for ext in pdo_mysql zip pcntl; do php -m | grep -qx "$ext" || need="$need $ext"; done; \
    if [ -n "$need" ]; then \
      apk add --no-cache libzip; \
      apk add --no-cache --virtual .build $PHPIZE_DEPS libzip-dev; \
      docker-php-ext-install -j"$(nproc)" $need; \
      apk del .build; \
    fi; \
    if [ "$INSTALL_MYSQL_CLIENT" = "1" ]; then apk add --no-cache mysql-client; fi
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /src /var/www/html
COPY --from=assets --chown=www-data:www-data /src/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /src/public /var/www/html/public
