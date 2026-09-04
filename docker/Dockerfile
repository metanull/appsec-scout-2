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
# BFG is not part of the shared static-analysis toolchain script — it's a Git-history
# tool, ops-only, so its pin lives here rather than in a script.
ARG BFG_VERSION=1.14.0
# The current Node 22 LTS ("Jod") patch at implementation time — bump alongside a
# routine review, not on any particular schedule.
ARG NODE_VERSION=22.23.2

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

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
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
    --mount=type=cache,target=/root/.npm \
    if [ -f /host-certs/host-ca-bundle.crt ]; then \
        export SSL_CERT_FILE=/host-certs/host-ca-bundle.crt; \
        export NODE_EXTRA_CA_CERTS=/host-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/host-certs/host-ca-bundle.crt; \
    fi; \
    npm ci --ignore-scripts

COPY app-laravel/ ./
COPY --from=composer-deps /app/vendor ./vendor

RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    --mount=type=cache,target=/root/.npm \
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

# Keep downloaded .deb packages instead of letting the base image's default apt config
# delete them after install — that default exists for image size (irrelevant to a cache
# mount, which never ships in the image), and keeping them is what lets the
# /var/cache/apt cache mount below actually save re-downloads across builds.
RUN rm -f /etc/apt/apt.conf.d/docker-clean \
    && echo 'Binary::apt::APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/keep-cache

# The upgrade applies Debian security updates the base image predates — the CI Trivy
# gate fails the build on any fixable HIGH/CRITICAL, so lagging base-image packages
# must be patched at build time.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
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

RUN --mount=type=cache,target=/tmp/pear \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl bcmath zip pcntl opcache \
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
RUN rm -f .env \
    && mkdir -p \
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

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    --mount=type=cache,target=/tmp/pear \
    apt-get update && apt-get install -y --no-install-recommends \
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

# --- dev (local only, never published): the app target with Composer dev dependencies
# (Pest, PHPStan, Larastan, Pint, Faker, Mockery, Pail, Collision) baked in, so the image
# is complete for checks without relying on the entrypoint's runtime `composer install`.
# composer-deps installed with --no-dev, so this reinstalls with dev packages included —
# composer.lock still lists them, this just adds what --no-dev skipped. ---
FROM app AS dev

WORKDIR /var/www/html

RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    --mount=type=cache,target=/tmp/composer-cache \
    sh /tmp/lib/install-ca-certs.sh \
    && COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
    && chown -R www-data:www-data vendor

# --- collector (published): lean CLI-only worker — git + Trivy + the Laravel app's PHP
# runtime. No nginx/php-fpm/supervisor (nothing here serves HTTP) and no build toolchain
# (.NET/Java/Maven/Gradle) — this container only runs `trivy fs` against a shallow
# clone, matching invoke-ops.ps1 -SbomScan's scope, not -StaticAnalysis's. ---
FROM laravel-base AS collector

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        wget \
        gnupg \
    && rm -rf /var/lib/apt/lists/*

RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
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

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        wget \
        gnupg \
        lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Every version pin (Roslynator, SpotBugs, Find Security Bugs, Maven, Gradle, Opengrep,
# the .NET channel) lives in the script, not here — see
# docker/lib/install-static-analysis-toolchain.sh for the full rationale on each one,
# including why .NET is installed from Microsoft's script rather than its apt repo.
RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
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

# --- ops (local only, never published): a sandboxed, ephemeral container for hands-on
# appsec investigation — code analysis, secret scanning, dependency auditing, SBOM
# generation, Git history cleaning, and running Claude Code, driven exclusively via
# scripts/invoke-ops.ps1. FROM php-runtime, not laravel-base: it carries no application
# code and must not inherit laravel-base's entrypoint. Inheriting php-runtime gives it
# the same official PHP build, compiled extension set, and 512M memory limit the app
# runs on, so Pint/PHPStan/Pest results here are directly comparable to CI and the app
# container — the whole reason this target exists instead of staying its own
# node:22-slim image with PHP bolted on via a different apt repo. ---
FROM php-runtime AS ops

# Every RUN below that pipes a download into another command (curl|dd, curl|bash, a
# checksum piped through sha1sum -c) needs this: POSIX sh (dash on Debian) has no
# pipefail, so a failed download upstream of a pipe would otherwise let the RUN exit 0
# on whatever the last command in the pipe reports. bash is already a hard runtime
# dependency of this target (docker/ops/entrypoint.sh's own shebang).
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

ARG BFG_VERSION
ARG NODE_VERSION
ARG OPENGREP_ENABLED

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        jq \
        gnupg \
        lsb-release \
        wget \
    && rm -rf /var/lib/apt/lists/*

# --- Node.js (official release tarball, checksum-verified) — Claude Code and audited
# JavaScript repositories need it. Installed from the tarball rather than an apt repo
# for the same reason the toolchain script installs .NET from a script: no third-party
# apt key to trust. ---
RUN curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz" \
        -o "/tmp/node-v${NODE_VERSION}-linux-x64.tar.xz" \
    && NODE_SHA256=$(curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/SHASUMS256.txt" \
        | grep "node-v${NODE_VERSION}-linux-x64.tar.xz" | awk '{print $1}') \
    && echo "${NODE_SHA256}  /tmp/node-v${NODE_VERSION}-linux-x64.tar.xz" | sha256sum -c - \
    && tar -xJf "/tmp/node-v${NODE_VERSION}-linux-x64.tar.xz" -C /usr/local --strip-components=1 \
    && rm "/tmp/node-v${NODE_VERSION}-linux-x64.tar.xz"

# --- GitHub CLI (signed apt repo) ---
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

# --- ops user (must exist before Claude Code/Composer run as non-root) ---
RUN useradd -m -s /bin/bash ops \
    && mkdir -p /home/ops/.claude /workspace \
    && chown ops:ops /home/ops/.claude /workspace

# --- Claude Code (native installer, installed as ops) ---
USER ops
WORKDIR /home/ops
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH="/home/ops/.local/bin:${PATH}"
USER root
WORKDIR /

# mbstring, xml and curl are compiled into the official php-runtime image already —
# confirmed with `php -m`, not reinstalled. imagick and pcov are ops-specific (the app
# needs imagick too, but compiles it itself in the app target; pcov is test-coverage
# tooling no published image needs).
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    --mount=type=cache,target=/tmp/pear \
    apt-get update && apt-get install -y --no-install-recommends \
        imagemagick \
        libmagickwand-dev \
    && pecl install imagick pcov \
    && docker-php-ext-enable imagick pcov \
    # Every PHP extension is compiled at this point. Purge linux-libc-dev — see app's
    # own purge step for the full rationale. node:22-slim never had a C toolchain either,
    # so npm native builds are no worse off than before this target existed.
    && apt-get purge -y linux-libc-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-deps /usr/bin/composer /usr/local/bin/composer

# --- Global PHP tools (run as ops — Pest plugin hooks require non-root) ---
USER ops
ENV COMPOSER_HOME=/home/ops/.config/composer
RUN composer global config allow-plugins.pestphp/pest-plugin true \
    && composer global require --no-interaction \
        phpstan/phpstan \
        laravel/pint \
        pestphp/pest
ENV PATH="${PATH}:/home/ops/.config/composer/vendor/bin"

USER root

RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    sh /tmp/lib/install-trivy.sh \
    && OPENGREP_ENABLED=${OPENGREP_ENABLED} sh /tmp/lib/install-static-analysis-toolchain.sh

ENV PATH="${PATH}:/usr/local/dotnet-tools" \
    JAVA_HOME=/opt/java-home

# --- BFG Repo Cleaner (checksum- and signature-verified) — a Git-history tool, not
# part of the shared static-analysis toolchain. ---
RUN gpg --keyserver hkps://keys.openpgp.org \
        --recv-keys 7305AE1B6772AA1C2D3B4E5DB21602FA2D5CC13C \
    && wget -q \
        "https://repo1.maven.org/maven2/com/madgag/bfg/${BFG_VERSION}/bfg-${BFG_VERSION}.jar" \
        -O /usr/local/lib/bfg.jar \
    && BFG_SHA1=$(curl -fsSL \
        "https://repo1.maven.org/maven2/com/madgag/bfg/${BFG_VERSION}/bfg-${BFG_VERSION}.jar.sha1") \
    && echo "${BFG_SHA1}  /usr/local/lib/bfg.jar" | sha1sum -c - \
    && wget -q \
        "https://repo1.maven.org/maven2/com/madgag/bfg/${BFG_VERSION}/bfg-${BFG_VERSION}.jar.asc" \
        -O /tmp/bfg.jar.asc \
    && gpg --verify /tmp/bfg.jar.asc /usr/local/lib/bfg.jar \
    && rm /tmp/bfg.jar.asc \
    && printf '#!/bin/sh\nexec java -jar /usr/local/lib/bfg.jar "$@"\n' \
        > /usr/local/bin/bfg \
    && chmod +x /usr/local/bin/bfg

RUN git config --global --add safe.directory /workspace \
    && su ops -c "git config --global --add safe.directory /workspace"

ENV NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt

WORKDIR /workspace
COPY docker/ops/entrypoint.sh /entrypoint.sh
COPY docker/ops/collect-sboms.sh /usr/local/bin/collect-sboms.sh
COPY docker/ops/collect-static-analysis.sh /usr/local/bin/collect-static-analysis.sh

RUN dos2unix /entrypoint.sh /usr/local/bin/collect-sboms.sh /usr/local/bin/collect-static-analysis.sh && \
    chmod +x /entrypoint.sh /usr/local/bin/collect-sboms.sh /usr/local/bin/collect-static-analysis.sh

USER ops
ENTRYPOINT ["/entrypoint.sh"]
