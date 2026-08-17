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

# --- final: lean CLI-only worker — git + the Laravel app's PHP runtime, plus the
# .NET/Java build+analysis toolchain (Roslynator, SpotBugs + Find Security Bugs).
# No nginx/php-fpm/supervisor (nothing here serves HTTP), no Trivy (this container
# never calls it, unlike docker/collector), and none of docker/ops/Dockerfile's
# interactive-shell tooling (GitHub CLI, Claude Code installer, BFG Repo Cleaner,
# global Pest/PHPStan/Pint) — this is a queue worker, not an operator shell. ---
FROM php:8.4-cli-trixie AS final

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

# Tool versions — update these ARGs to pin to newer releases. Kept in lockstep
# with docker/ops/Dockerfile's own pins for the same tools.
#
# Roslynator.DotNet.Cli (the NuGet package providing the `roslynator` CLI) has
# its own independent version scheme, separate from the main dotnet/roslynator
# repo's 4.x tags — do not confuse the two. 0.13.1 is the latest published
# version (confirmed against NuGet's own API) and is required, not optional:
# 0.12.0 cannot analyze a solution under .NET 10 SDK at all
# (System.MissingMethodException from its bundled MSBuild assemblies — see
# issue #368), so this is a compatibility fix, not just a currency bump.
ARG ROSLYNATOR_VERSION=0.13.1
ARG SPOTBUGS_VERSION=4.10.3
ARG FINDSECBUGS_VERSION=1.14.0
ARG MAVEN_VERSION=3.9.16
ARG GRADLE_VERSION=9.7.0

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
        lsb-release \
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

# --- .NET 10 SDK — Microsoft's official install script (served over HTTPS from
# Microsoft's own CDN), not the packages.microsoft.com apt repo docker/ops/Dockerfile
# uses: that repo's signing key currently fails Debian trixie's sequoia-based apt
# signature policy (its self-signature predates SHA-256 re-certification and is
# rejected as "SHA1 is not considered secure since 2026-02-01") — an upstream
# Microsoft key-hygiene issue outside this repo's control, not something to work
# around by weakening apt's signature verification. The install script is
# Microsoft's own documented approach for container/CI use and avoids the apt/gpg
# trust chain entirely.
ARG DOTNET_CHANNEL=10.0
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
ARG SPOTBUGS_SHA256=53c03a77da9746ed0c17aae6c0a9419a12ddeb8bf61dd7209a2e417550afd01d
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
CMD ["php", "artisan", "queue:work", "--queue=static-analysis", "--tries=3", "--timeout=1800", "--max-time=3600"]
