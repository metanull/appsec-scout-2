# syntax=docker/dockerfile:1

FROM node:22-slim

ARG HTTP_PROXY=""
ARG HTTPS_PROXY=""
ARG NO_PROXY=""

# Tool versions — update these ARGs to pin to newer releases
ARG TRIVY_VERSION=0.71.1
ARG BFG_VERSION=1.14.0
ARG ROSLYNATOR_VERSION=0.12.0

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

COPY .docker/certs/ /tmp/appsec-scout-certs/

# --- Layer 1: Base packages + custom CA certs (mirrors claude Dockerfile) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        ca-certificates \
        curl \
        jq \
        gnupg \
        lsb-release \
        unzip \
        wget \
    && if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
         find /tmp/appsec-scout-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
             -exec cp {} /usr/local/share/ca-certificates/ \; ; \
         update-ca-certificates; \
       fi \
    && rm -rf /var/lib/apt/lists/*

# --- Layer 2: GitHub CLI (mirrors claude Dockerfile) ---
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

# --- Layer 3: Claude Code (mirrors claude Dockerfile) ---
RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        export NODE_EXTRA_CA_CERTS=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
    fi; \
    npm install -g @anthropic-ai/claude-code

# --- Layer 4: PHP 8.4 + extensions (Sury signed repo, no fpm/nginx) ---
RUN curl -fsSL https://packages.sury.org/php/apt.gpg \
        | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
        > /etc/apt/sources.list.d/sury-php.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli \
        php8.4-intl \
        php8.4-bcmath \
        php8.4-zip \
        php8.4-xml \
        php8.4-mbstring \
        php8.4-curl \
        php8.4-mysql \
        php8.4-imagick \
        php8.4-redis \
        php8.4-pcov \
    && rm -rf /var/lib/apt/lists/*

# --- Layer 5: Create ops user (must exist before Composer runs as non-root) ---
RUN useradd -m -s /bin/bash ops \
    && mkdir -p /home/ops/.claude /workspace \
    && chown ops:ops /home/ops/.claude /workspace

# --- Layer 6: Composer binary (installed as root to /usr/local/bin) ---
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && EXPECTED_HASH=$(curl -fsSL https://composer.github.io/installer.sha384sum | awk '{print $1}') \
    && ACTUAL_HASH=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');") \
    && if [ "$EXPECTED_HASH" != "$ACTUAL_HASH" ]; then \
           echo "ERROR: Composer installer checksum mismatch"; exit 1; \
       fi \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

# --- Layer 7: Global PHP tools (run as ops — Pest plugin hooks require non-root) ---
USER ops
ENV COMPOSER_HOME=/home/ops/.config/composer
RUN composer global config allow-plugins.pestphp/pest-plugin true \
    && composer global require --no-interaction \
        phpstan/phpstan \
        laravel/pint \
        pestphp/pest
ENV PATH="${PATH}:/home/ops/.config/composer/vendor/bin"

# Resume root for all remaining system-level installs
USER root

# --- Layer 8: .NET 10 SDK ---
RUN wget -q "https://packages.microsoft.com/config/debian/12/packages-microsoft-prod.deb" \
        -O /tmp/packages-microsoft-prod.deb \
    && dpkg -i /tmp/packages-microsoft-prod.deb \
    && rm /tmp/packages-microsoft-prod.deb \
    && apt-get update && apt-get install -y --no-install-recommends dotnet-sdk-10.0 \
    && rm -rf /var/lib/apt/lists/*

# --- Layer 9: Roslynator CLI (shared tool path, accessible to all users) ---
RUN dotnet tool install \
        --version ${ROSLYNATOR_VERSION} \
        --tool-path /usr/local/dotnet-tools \
        roslynator.dotnet.cli

ENV PATH="${PATH}:/usr/local/dotnet-tools"

# --- Layer 10: Trivy (Aqua Security signed apt repo) ---
RUN wget -qO- https://aquasecurity.github.io/trivy-repo/deb/public.key \
        | gpg --dearmor -o /usr/share/keyrings/trivy.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
        > /etc/apt/sources.list.d/trivy.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends trivy=${TRIVY_VERSION} \
    && rm -rf /var/lib/apt/lists/*

# --- Layer 11: BFG Repo Cleaner + Java (checksum-verified) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        default-jre-headless \
    && rm -rf /var/lib/apt/lists/* \
    && gpg --keyserver hkps://keys.openpgp.org \
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

# --- Layer 12: Git config, entrypoint, final user switch ---
RUN git config --global --add safe.directory /workspace \
    && su ops -c "git config --global --add safe.directory /workspace"

ENV NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt

WORKDIR /workspace
COPY docker/ops/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER ops
ENTRYPOINT ["/entrypoint.sh"]
