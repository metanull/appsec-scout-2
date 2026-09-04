# syntax=docker/dockerfile:1

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY
ARG BUILD_APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

# Tool versions — update these ARGs to pin to newer releases.
#
# Roslynator.DotNet.Cli (the NuGet package providing the `roslynator` CLI) has
# its own independent version scheme, separate from the main dotnet/roslynator
# repo's 4.x tags — do not confuse the two. 0.13.1 is the latest published
# version (confirmed against NuGet's own API) and is required, not optional:
# 0.12.0 cannot analyze a solution under .NET 10 SDK at all
# (System.MissingMethodException from its bundled MSBuild assemblies — see
# issue #368), so this is a compatibility fix, not just a currency bump.
ARG DOTNET_CHANNEL=10.0
ARG ROSLYNATOR_VERSION=0.13.1
ARG SPOTBUGS_VERSION=4.10.3
ARG SPOTBUGS_SHA256=53c03a77da9746ed0c17aae6c0a9419a12ddeb8bf61dd7209a2e417550afd01d
ARG FINDSECBUGS_VERSION=1.14.0
ARG MAVEN_VERSION=3.9.16
ARG GRADLE_VERSION=9.7.0
# Opengrep (https://github.com/opengrep/opengrep) — LGPL-2.1 community fork of Semgrep, a
# single self-contained static binary, no Python runtime, native SARIF output. Analyzes
# C#/Java/JavaScript/TypeScript source directly, no build required.
ARG OPENGREP_VERSION=1.27.1
ARG OPENGREP_SHA256=58053da76672bbeb5b0a5441021c58338707052e10f81d777140ca879bd491ce
ARG OPENGREP_RULES_REF=f1d2b562b414783763fd02a6ed2736eaed622efa
# Set to "false" where corporate network policy blocks fetching the pinned binary from
# GitHub releases (e.g. Netskope file-type/DLP rules) — the Opengrep install layer below is
# then skipped entirely and collect-static-analysis.sh detects the missing binary at runtime
# and skips the Opengrep step on its own, no other flag needed.
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

# --- Trivy (Aqua Security signed apt repo) — scans against the shared trivy-server
# container (docker-compose.yml), never downloads its own vulnerability database. ---
RUN wget -qO- https://aquasecurity.github.io/trivy-repo/deb/public.key \
        | gpg --dearmor -o /usr/share/keyrings/trivy.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
        > /etc/apt/sources.list.d/trivy.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends trivy \
    # Every PHP extension is compiled in php-runtime. Purge linux-libc-dev — see app's
    # own purge step for the full rationale.
    && apt-get purge -y linux-libc-dev \
    && rm -rf /var/lib/apt/lists/*

# -v: full exception traces on job failure go to stdout, not just a one-line summary.
CMD ["php", "artisan", "queue:work", "--queue=repository-collection", "--tries=3", "--timeout=1800", "--max-time=3600", "-v"]

# --- static-analysis-collector (published): lean CLI-only worker — git + the Laravel
# app's PHP runtime, plus the .NET/Java build+analysis toolchain (Roslynator, SpotBugs +
# Find Security Bugs). No nginx/php-fpm/supervisor (nothing here serves HTTP), no Trivy
# (this container never calls it, unlike collector), and none of ops's interactive-shell
# tooling (GitHub CLI, Claude Code installer, BFG Repo Cleaner, global Pest/PHPStan/Pint)
# — this is a queue worker, not an operator shell. ---
FROM laravel-base AS static-analysis-collector

ARG DOTNET_CHANNEL
ARG ROSLYNATOR_VERSION
ARG SPOTBUGS_VERSION
ARG SPOTBUGS_SHA256
ARG FINDSECBUGS_VERSION
ARG MAVEN_VERSION
ARG GRADLE_VERSION
ARG OPENGREP_VERSION
ARG OPENGREP_SHA256
ARG OPENGREP_RULES_REF
ARG OPENGREP_ENABLED

RUN apt-get update && apt-get install -y --no-install-recommends \
        wget \
        gnupg \
        lsb-release \
    && rm -rf /var/lib/apt/lists/*

# --- .NET 10 SDK — Microsoft's official install script (served over HTTPS from
# Microsoft's own CDN), not the packages.microsoft.com apt repo the ops target uses:
# that repo's signing key currently fails Debian trixie's sequoia-based apt signature
# policy (its self-signature predates SHA-256 re-certification and is rejected as "SHA1
# is not considered secure since 2026-02-01") — an upstream Microsoft key-hygiene issue
# outside this repo's control, not something to work around by weakening apt's signature
# verification. The install script is Microsoft's own documented approach for
# container/CI use and avoids the apt/gpg trust chain entirely.
RUN curl -fsSL https://dot.net/v1/dotnet-install.sh -o /tmp/dotnet-install.sh \
    && chmod +x /tmp/dotnet-install.sh \
    && /tmp/dotnet-install.sh --channel ${DOTNET_CHANNEL} --install-dir /usr/share/dotnet \
    && rm /tmp/dotnet-install.sh \
    && ln -s /usr/share/dotnet/dotnet /usr/local/bin/dotnet

# --- Roslynator CLI (shared tool path, accessible to all users) ---
RUN dotnet tool install \
        --version ${ROSLYNATOR_VERSION} \
        --tool-path /usr/local/dotnet-tools \
        roslynator.dotnet.cli

ENV PATH="${PATH}:/usr/local/dotnet-tools"

# --- Java (Eclipse Temurin JDK, current LTS — signed Adoptium apt repo) ---
RUN curl -fsSL https://packages.adoptium.net/artifactory/api/gpg/key/public \
        | gpg --dearmor -o /usr/share/keyrings/adoptium.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/adoptium.gpg] https://packages.adoptium.net/artifactory/deb $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/adoptium.list \
    && apt-get update && apt-get install -y --no-install-recommends temurin-25-jdk \
    && rm -rf /var/lib/apt/lists/*

# Fixed, architecture-independent path to the JDK just installed — the real Adoptium apt
# package path is architecture-specific (e.g. .../temurin-25-jdk-amd64 vs -arm64), so this
# symlink lets JAVA_HOME below stay a single value regardless of build host architecture.
RUN ln -s "$(dirname "$(dirname "$(readlink -f "$(which javac)")")")" /opt/java-home
ENV JAVA_HOME=/opt/java-home

# --- Maven + Gradle (build Java repos ahead of SpotBugs, which needs compiled .class
# files, not source) — installed from the official Apache/Gradle release archives with a
# verified checksum. Repos that ship their own wrapper (mvnw/gradlew) use that instead;
# these are the fallback for repos that don't. ---
RUN wget -q "https://downloads.apache.org/maven/maven-3/${MAVEN_VERSION}/binaries/apache-maven-${MAVEN_VERSION}-bin.tar.gz" \
        -O /tmp/maven.tar.gz \
    && MAVEN_SHA512=$(curl -fsSL \
        "https://downloads.apache.org/maven/maven-3/${MAVEN_VERSION}/binaries/apache-maven-${MAVEN_VERSION}-bin.tar.gz.sha512" \
        | awk '{print $1}') \
    && echo "${MAVEN_SHA512}  /tmp/maven.tar.gz" | sha512sum -c - \
    && mkdir -p /opt/maven \
    && tar -xzf /tmp/maven.tar.gz -C /opt/maven --strip-components=1 \
    && rm /tmp/maven.tar.gz \
    && ln -s /opt/maven/bin/mvn /usr/local/bin/mvn

RUN wget -q "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip" \
        -O /tmp/gradle.zip \
    && GRADLE_SHA256=$(curl -fsSL \
        "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip.sha256" \
        | awk '{print $1}') \
    && echo "${GRADLE_SHA256}  /tmp/gradle.zip" | sha256sum -c - \
    && mkdir -p /opt/gradle-extract \
    && unzip -q /tmp/gradle.zip -d /opt/gradle-extract \
    && mv "/opt/gradle-extract/gradle-${GRADLE_VERSION}" /opt/gradle \
    && rm -rf /tmp/gradle.zip /opt/gradle-extract \
    && ln -s /opt/gradle/bin/gradle /usr/local/bin/gradle

# --- SpotBugs + Find Security Bugs plugin (checksum- and signature-verified) ---
# SHA256 published at https://github.com/spotbugs/spotbugs/releases/tag/4.10.3
RUN wget -q \
        "https://github.com/spotbugs/spotbugs/releases/download/${SPOTBUGS_VERSION}/spotbugs-${SPOTBUGS_VERSION}.tgz" \
        -O /tmp/spotbugs.tgz \
    && echo "${SPOTBUGS_SHA256}  /tmp/spotbugs.tgz" | sha256sum -c - \
    && mkdir -p /opt/spotbugs \
    && tar -xzf /tmp/spotbugs.tgz -C /opt/spotbugs --strip-components=1 \
    && rm /tmp/spotbugs.tgz \
    && ln -s /opt/spotbugs/bin/spotbugs /usr/local/bin/spotbugs \
    && mkdir -p /opt/spotbugs-plugins \
    && wget -q \
        "https://repo1.maven.org/maven2/com/h3xstream/findsecbugs/findsecbugs-plugin/${FINDSECBUGS_VERSION}/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar" \
        -O "/opt/spotbugs-plugins/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar" \
    && FINDSECBUGS_SHA1=$(curl -fsSL \
        "https://repo1.maven.org/maven2/com/h3xstream/findsecbugs/findsecbugs-plugin/${FINDSECBUGS_VERSION}/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar.sha1") \
    && echo "${FINDSECBUGS_SHA1}  /opt/spotbugs-plugins/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar" | sha1sum -c - \
    && wget -q \
        "https://repo1.maven.org/maven2/com/h3xstream/findsecbugs/findsecbugs-plugin/${FINDSECBUGS_VERSION}/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar.asc" \
        -O /tmp/findsecbugs.jar.asc \
    && gpg --keyserver hkps://keyserver.ubuntu.com \
        --recv-keys CFC10B69382CBCF5387E51484ECE492B63E38ACF \
    && gpg --verify /tmp/findsecbugs.jar.asc "/opt/spotbugs-plugins/findsecbugs-plugin-${FINDSECBUGS_VERSION}.jar" \
    && rm /tmp/findsecbugs.jar.asc

# --- Opengrep binary + rules ---
# opengrep_manylinux_x86 is the exact asset name opengrep's own install.sh resolves for a
# glibc-based Linux x86_64 host (as opposed to the musllinux/osx/windows/aarch64 variants
# also published on the same release). Opengrep publishes cosign signatures (.cert/.sig) for
# each binary but no plain sha256sums file, so this checksum was computed directly from the
# binary at OPENGREP_VERSION, downloaded from this exact release URL over HTTPS.
#
# Only csharp/java/javascript/typescript are vendored from opengrep-rules — generic/ (secret
# detection) is deliberately excluded, since Trivy already covers secrets in the
# repository-collection pipeline.
#
# Only the *.yaml/*.yml rule definitions are kept from those directories — opengrep's -f
# directory loader ignores everything else, and the accompanying *.test.* fixtures are
# intentionally-vulnerable example code (the thing each rule is meant to catch), which some
# corporate TLS-inspecting proxies flag as malicious content when this layer is pulled.
#
# Guarded by OPENGREP_ENABLED (see its ARG declaration above) — when "false", none of this
# runs and /usr/local/bin/opengrep is never created.
RUN if [ "$OPENGREP_ENABLED" = "true" ]; then \
        wget -q \
            "https://github.com/opengrep/opengrep/releases/download/v${OPENGREP_VERSION}/opengrep_manylinux_x86" \
            -O /usr/local/bin/opengrep \
        && echo "${OPENGREP_SHA256}  /usr/local/bin/opengrep" | sha256sum -c - \
        && chmod +x /usr/local/bin/opengrep \
        && opengrep --version \
        && wget -q \
            "https://github.com/opengrep/opengrep-rules/archive/${OPENGREP_RULES_REF}.tar.gz" \
            -O /tmp/opengrep-rules.tar.gz \
        && mkdir -p /tmp/opengrep-rules-extract /opt/opengrep-rules \
        && tar -xzf /tmp/opengrep-rules.tar.gz -C /tmp/opengrep-rules-extract --strip-components=1 \
        && cp -r /tmp/opengrep-rules-extract/csharp /tmp/opengrep-rules-extract/java \
            /tmp/opengrep-rules-extract/javascript /tmp/opengrep-rules-extract/typescript \
            /opt/opengrep-rules/ \
        && find /opt/opengrep-rules -type f ! -name '*.yaml' ! -name '*.yml' -delete \
        && rm -rf /tmp/opengrep-rules.tar.gz /tmp/opengrep-rules-extract; \
    else \
        echo "OPENGREP_ENABLED=false — skipping Opengrep binary/rules install."; \
    fi

# Every PHP extension is compiled in php-runtime, and the .NET SDK/JDK toolchain above
# compiles analyzed repos itself without the system C toolchain — see app's own purge
# step for the full linux-libc-dev rationale.
RUN apt-get purge -y linux-libc-dev

CMD ["php", "artisan", "queue:work", "--queue=static-analysis", "--tries=3", "--timeout=1800", "--max-time=3600"]
