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

# --- final: lean CLI-only worker — git + Trivy + the Laravel app's PHP runtime.
# No nginx/php-fpm/supervisor (nothing here serves HTTP) and no build toolchain
# (.NET/Java/Maven/Gradle) — this container only runs `trivy fs` against a shallow
# clone, matching invoke-ops.ps1 -SbomScan's scope, not -StaticAnalysis's. ---
FROM php:8.4-cli-trixie AS final

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
        git \
        ca-certificates \
        curl \
        wget \
        gnupg \
        dos2unix \
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
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# --- Trivy (Aqua Security signed apt repo) — scans against the shared trivy-server
# container (docker-compose.yml), never downloads its own vulnerability database. ---
RUN wget -qO- https://aquasecurity.github.io/trivy-repo/deb/public.key \
        | gpg --dearmor -o /usr/share/keyrings/trivy.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
        > /etc/apt/sources.list.d/trivy.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends trivy \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer-deps /app ./

# Remove the build-time .env; the entrypoint restores the persisted one from the
# app_storage volume this container shares (read) with `app` — see docker/entrypoint.sh.
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
        storage/logs

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN dos2unix /usr/local/bin/entrypoint.sh && \
    chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# -v: full exception traces on job failure go to stdout, not just a one-line summary.
CMD ["php", "artisan", "queue:work", "--queue=repository-collection", "--tries=3", "--timeout=1800", "--max-time=3600", "-v"]
