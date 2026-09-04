# syntax=docker/dockerfile:1

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY
ARG BUILD_APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
# Set to "false" where corporate network policy blocks fetching the pinned Opengrep binary
# from GitHub releases (e.g. Netskope file-type/DLP rules) — see
# docker/lib/install-static-analysis-toolchain.sh, which owns every other toolchain
# version pin, for what this disables and why it's the one pin surfaced as a build arg.
ARG OPENGREP_ENABLED=true

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

RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    sh /tmp/lib/install-ca-certs.sh

RUN apk add --no-cache icu-dev \
    && docker-php-ext-install intl

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
RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    if [ -f /host-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/host-certs/host-ca-bundle.crt; \
        export NODE_EXTRA_CA_CERTS=/host-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/host-certs/host-ca-bundle.crt; \
    fi; \
    npm ci --ignore-scripts

COPY app-laravel/ ./
COPY --from=composer-deps /app/vendor ./vendor

RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    if [ -f /host-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/host-certs/host-ca-bundle.crt; \
        export NODE_EXTRA_CA_CERTS=/host-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/host-certs/host-ca-bundle.crt; \
    fi; \
    npm run build

# --- php-runtime: the PHP build every final image shares — official php:8.4-fpm-trixie
# (the fpm variant is used everywhere, including the CLI-only workers, since it costs
# nothing unused and keeps exactly one PHP build across every image), the apt packages
# and compiled extensions common to app/collector/static-analysis-collector, and the
# corporate-CA trust store. linux-libc-dev is *not* purged here: app and ops still
# compile extensions (imagick, pcov) after this stage, so each final target purges it
# itself once its own extensions are compiled. ---
FROM php:8.4-fpm-trixie AS php-runtime

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

# The upgrade applies Debian security updates the base image predates — the CI Trivy
# gate fails the build on any fixable HIGH/CRITICAL, so lagging base-image packages
# must be patched at build time.
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        dos2unix \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    sh /tmp/lib/install-ca-certs.sh

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl bcmath zip pcntl opcache \
    && printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/appsec-scout-memory-limit.ini \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# --- laravel-base: the application code, storage layout, Composer binary and entrypoint
# shared by every image that runs the Laravel app (app, collector, static-analysis-collector —
# ops does not, since it carries no app code; see docker/ops's former Dockerfile, now the
# `ops` target below, FROM php-runtime directly). ---
FROM php-runtime AS laravel-base

COPY --from=composer-deps /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY --from=composer-deps /app ./

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
        storage/logs

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/lib/install-ca-certs.sh /usr/local/bin/install-ca-certs.sh

RUN dos2unix /usr/local/bin/entrypoint.sh /usr/local/bin/install-ca-certs.sh && \
    chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/install-ca-certs.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# --- app (published): nginx + php-fpm + Supervisor, serving the Filament UI. ---
FROM laravel-base AS app

# HTTP_PROXY/HTTPS_PROXY/NO_PROXY need no redeclaration here — php-runtime's ENV (not
# ARG, which does not survive a stage boundary) is already part of this stage's base
# image and is inherited automatically through laravel-base.
ARG BUILD_APP_KEY

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        imagemagick \
        libmagickwand-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    # Every PHP extension is compiled at this point. Purge linux-libc-dev — the kernel
    # headers the base image ships for phpize. Its weekly kernel CVEs are meaningless in
    # a container (no kernel runs here) and would permanently trip the CI Trivy publish
    # gate. The purge cascades to the C build toolchain (g++, libc6-dev, *-dev headers);
    # the runtime shared libraries the compiled extensions link against are unaffected.
    && apt-get purge -y linux-libc-dev \
    && rm -rf /var/lib/apt/lists/*

RUN printf "[global]\ndaemonize = no\nerror_log = /proc/self/fd/2\npid = /tmp/php-fpm.pid\n\n[www]\nclear_env = no\nlisten = 127.0.0.1:9000\naccess.log = /proc/self/fd/2\ncatch_workers_output = yes\ndecorate_workers_output = no\n" > /usr/local/etc/php-fpm.d/zz-appsec-runtime.conf

COPY docker/nginx-main.conf /etc/nginx/nginx.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

RUN mkdir -p public/css public/js

COPY --from=assets /app/public/build ./public/build

# Publish Filament assets using a throwaway build-time key (ARG is not persisted in the image ENV).
RUN APP_KEY=${BUILD_APP_KEY} php artisan filament:assets --no-interaction \
    && rm -f bootstrap/cache/*.php \
    && test -f public/css/filament/filament/app.css \
    && test -f public/js/filament/filament/app.js

# Compose mounts named volumes over public/build, public/css, and public/js so that
# vendor/build output isn't shadowed by the app-laravel bind mount. Those volumes are
# only ever seeded from the image the first time they're created — a later image rebuild
# does not by itself refresh them. Keep a reference copy outside any volume-mounted path
# so the entrypoint can resync it into the live paths on every container start.
RUN mkdir -p /opt/baked-assets \
    && cp -r public/build /opt/baked-assets/build \
    && cp -r public/css /opt/baked-assets/css \
    && cp -r public/js /opt/baked-assets/js

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# --- collector (published): lean CLI-only worker — git + Trivy + the Laravel app's PHP
# runtime. No nginx/php-fpm/supervisor (nothing here serves HTTP) and no build toolchain
# (.NET/Java/Maven/Gradle) — this container only runs `trivy fs` against a shallow
# clone, matching invoke-ops.ps1 -SbomScan's scope, not -StaticAnalysis's. ---
FROM laravel-base AS collector

RUN apt-get update && apt-get install -y --no-install-recommends \
        wget \
        gnupg \
    && rm -rf /var/lib/apt/lists/*

RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    sh /tmp/lib/install-trivy.sh

# Every PHP extension is compiled in php-runtime. Purge linux-libc-dev — see app's
# own purge step for the full rationale.
RUN apt-get purge -y linux-libc-dev

# -v: full exception traces on job failure go to stdout, not just a one-line summary.
CMD ["php", "artisan", "queue:work", "--queue=repository-collection", "--tries=3", "--timeout=1800", "--max-time=3600", "-v"]

# --- static-analysis-collector (published): lean CLI-only worker — git + the Laravel
# app's PHP runtime, plus the .NET/Java build+analysis toolchain (Roslynator, SpotBugs +
# Find Security Bugs). No nginx/php-fpm/supervisor (nothing here serves HTTP), no Trivy
# (this container never calls it, unlike collector), and none of ops's interactive-shell
# tooling (GitHub CLI, Claude Code installer, BFG Repo Cleaner, global Pest/PHPStan/Pint)
# — this is a queue worker, not an operator shell. ---
FROM laravel-base AS static-analysis-collector

ARG OPENGREP_ENABLED

RUN apt-get update && apt-get install -y --no-install-recommends \
        wget \
        gnupg \
        lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Every version pin (Roslynator, SpotBugs, Find Security Bugs, Maven, Gradle, Opengrep,
# the .NET channel) lives in the script, not here — see
# docker/lib/install-static-analysis-toolchain.sh for the full rationale on each one,
# including why .NET is installed from Microsoft's script rather than its apt repo.
RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    OPENGREP_ENABLED=${OPENGREP_ENABLED} sh /tmp/lib/install-static-analysis-toolchain.sh

# PATH/JAVA_HOME can't be set from inside the script — it only affects the RUN step's
# own shell, not the image config — so the caller sets them here.
ENV PATH="${PATH}:/usr/local/dotnet-tools" \
    JAVA_HOME=/opt/java-home

# Every PHP extension is compiled in php-runtime, and the .NET SDK/JDK toolchain above
# compiles analyzed repos itself without the system C toolchain — see app's own purge
# step for the full linux-libc-dev rationale.
RUN apt-get purge -y linux-libc-dev

CMD ["php", "artisan", "queue:work", "--queue=static-analysis", "--tries=3", "--timeout=1800", "--max-time=3600"]
