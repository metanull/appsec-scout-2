# syntax=docker/dockerfile:1

# ── Stage 1: resolve Composer dependencies ───────────────────────────────────
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

COPY .docker/certs/ /tmp/appsec-scout-certs/

RUN if find /tmp/appsec-scout-certs -type f -name '*.crt' -print -quit | grep -q .; then \
        find /tmp/appsec-scout-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
            -exec cp {} /usr/local/share/ca-certificates/ \; ; \
        update-ca-certificates; \
    fi

COPY app-laravel/composer.json app-laravel/composer.lock ./

RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export CURL_CA_BUNDLE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export COMPOSER_CAFILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
    fi; \
    composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY app-laravel/ ./

RUN composer dump-autoload --no-dev --optimize --no-scripts

# ── Stage 2: build Filament/Vite assets ──────────────────────────────────────
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

COPY .docker/certs/ /tmp/appsec-scout-certs/

RUN if find /tmp/appsec-scout-certs -type f -name '*.crt' -print -quit | grep -q .; then \
    cat /tmp/appsec-scout-certs/host-ca-bundle.crt >> /etc/ssl/certs/ca-certificates.crt; \
    cat /tmp/appsec-scout-certs/host-ca-bundle.crt >> /etc/ssl/cert.pem; \
    fi

COPY app-laravel/package.json app-laravel/package-lock.json* ./

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

# ── Stage 3: production PHP-FPM image ────────────────────────────────────────
FROM php:8.4-fpm-trixie AS final

ARG BFG_VERSION=1.15.0
ARG BFG_SHA256=dfe2885adc2916379093f02a80181200536856c9a987bf21c492e452adefef7a
ARG TRIVY_VERSION=0.70.0
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

# System packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    git \
    curl \
    gpg \
    unzip \
    ca-certificates \
    libicu-dev \
    libzip-dev \
    && if find /tmp/appsec-scout-certs -type f -name '*.crt' -print -quit | grep -q .; then \
        find /tmp/appsec-scout-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
            -exec cp {} /usr/local/share/ca-certificates/ \; ; \
        update-ca-certificates; \
    fi \
    && install -d -m 0755 /etc/apt/keyrings \
    && curl -fsSL https://aquasecurity.github.io/trivy-repo/deb/public.key \
        | gpg --dearmor -o /etc/apt/keyrings/trivy.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
        > /etc/apt/sources.list.d/trivy.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        openjdk-21-jre-headless \
        trivy=${TRIVY_VERSION} \
    && rm -rf /var/lib/apt/lists/*

# Download BFG 1.15.0 jar (SHA-256 verified)
RUN mkdir -p /opt/bfg \
    && curl -fsSL \
        "https://repo1.maven.org/maven2/com/madgag/bfg/${BFG_VERSION}/bfg-${BFG_VERSION}.jar" \
        -o /opt/bfg/bfg.jar \
    && echo "${BFG_SHA256}  /opt/bfg/bfg.jar" \
        | sha256sum --check --status

# PHP extensions
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql intl bcmath zip pcntl opcache

RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/appsec-scout-memory-limit.ini

# Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Expose container environment variables to PHP-FPM workers and keep runtime
# state in writable tmpfs-backed paths so the container can run read-only.
RUN printf "[global]\ndaemonize = no\nerror_log = /proc/self/fd/2\npid = /tmp/php-fpm.pid\n\n[www]\nclear_env = no\nlisten = 127.0.0.1:9000\naccess.log = /proc/self/fd/2\ncatch_workers_output = yes\ndecorate_workers_output = no\n" > /usr/local/etc/php-fpm.d/zz-appsec-runtime.conf

# PHP-FPM config: use www-data (uid 33) — already exists in Debian-based PHP images
COPY docker/nginx-main.conf /etc/nginx/nginx.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer-deps /app ./
COPY --from=assets /app/public/build ./public/build

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
    && APP_ENV=production \
        LOG_STACK=stderr \
        CACHE_STORE=array \
        SESSION_DRIVER=array \
        QUEUE_CONNECTION=sync \
        php artisan filament:assets --no-interaction \
    && test -f public/css/filament/filament/app.css \
    && test -f public/js/filament/filament/app.js

RUN touch .env \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

USER www-data

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# ── Stage 4: development / CI image ─────────────────────────────────────────
FROM final AS dev

USER root

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts

USER www-data
