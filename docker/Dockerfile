# syntax=docker/dockerfile:1

FROM composer:2 AS composer-deps

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

WORKDIR /app

COPY app-laravel/composer.json app-laravel/composer.lock ./
COPY .docker/certs/ /tmp/appsec-scout-certs/

RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        find /tmp/appsec-scout-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
            -exec cp {} /usr/local/share/ca-certificates/ \; ; \
        update-ca-certificates; \
    fi

RUN apk add --no-cache icu-dev \
    && docker-php-ext-install intl

RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export CURL_CA_BUNDLE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export COMPOSER_CAFILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
    fi;

RUN composer install \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts

COPY app-laravel/ ./

RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/app/triage \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && composer dump-autoload --optimize --no-scripts

FROM node:22-alpine AS assets

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

WORKDIR /app

COPY app-laravel/package.json app-laravel/package-lock.json* ./
COPY .docker/certs/ /tmp/appsec-scout-certs/
RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export NODE_EXTRA_CA_CERTS=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
    fi; \
    npm ci --ignore-scripts

COPY app-laravel/ ./
COPY --from=composer-deps /app/vendor ./vendor

RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export NODE_EXTRA_CA_CERTS=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
    fi; \
    npm run build

FROM php:8.4-fpm-trixie AS final

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

COPY .docker/certs/ /tmp/appsec-scout-certs/

COPY --from=composer-deps /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        ca-certificates \
        git \
        imagemagick \
        libmagickwand-dev \
        libicu-dev \
        libzip-dev \
        unzip \
    && if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        find /tmp/appsec-scout-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
            -exec cp {} /usr/local/share/ca-certificates/ \; ; \
        update-ca-certificates; \
    fi \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl bcmath zip pcntl opcache \
    && printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/appsec-scout-memory-limit.ini \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

RUN printf "[global]\ndaemonize = no\nerror_log = /proc/self/fd/2\npid = /tmp/php-fpm.pid\n\n[www]\nclear_env = no\nlisten = 127.0.0.1:9000\naccess.log = /proc/self/fd/2\ncatch_workers_output = yes\ndecorate_workers_output = no\n" > /usr/local/etc/php-fpm.d/zz-appsec-runtime.conf

COPY docker/nginx-main.conf /etc/nginx/nginx.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer-deps /app ./
COPY --from=assets /app/public/build ./public/build

# Remove the build-time .env; runtime .env is managed by the entrypoint via app_storage volume.
RUN rm -f .env

RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/app/triage \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        public/css \
        public/js

# Publish Filament assets using a throwaway build-time key (ARG is not persisted in the image ENV).
ARG BUILD_APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
RUN APP_KEY=${BUILD_APP_KEY} php artisan filament:assets --no-interaction \
    && rm -f bootstrap/cache/*.php \
    && test -f public/css/filament/filament/app.css \
    && test -f public/js/filament/filament/app.js

RUN chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
