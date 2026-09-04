# syntax=docker/dockerfile:1

FROM node:22-slim

ARG HTTP_PROXY=""
ARG HTTPS_PROXY=""
ARG NO_PROXY=""

# BFG is not part of the shared static-analysis toolchain (docker/lib/
# install-static-analysis-toolchain.sh) — it's a Git-history tool, ops-only, so its pin
# stays local.
ARG BFG_VERSION=1.14.0
# Set to "false" where corporate network policy blocks fetching the pinned Opengrep binary
# from GitHub releases (e.g. Netskope file-type/DLP rules) — see
# docker/lib/install-static-analysis-toolchain.sh, which owns every other toolchain
# version pin, for what this disables and why it's the one pin surfaced as a build arg.
ARG OPENGREP_ENABLED=true

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

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
    && rm -rf /var/lib/apt/lists/*

RUN --mount=type=bind,source=.docker/certs,target=/host-certs,ro \
    --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    sh /tmp/lib/install-ca-certs.sh

# --- Layer 2: GitHub CLI (mirrors claude Dockerfile) ---
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

# --- Layer 3: Create ops user (must exist before Claude Code/Composer run as non-root) ---
RUN useradd -m -s /bin/bash ops \
    && mkdir -p /home/ops/.claude /workspace \
    && chown ops:ops /home/ops/.claude /workspace

# --- Layer 4: Claude Code (native installer, installed as ops) ---
USER ops
WORKDIR /home/ops
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH="/home/ops/.local/bin:${PATH}"
USER root
WORKDIR /

# --- Layer 5: PHP 8.4 + extensions (Sury signed repo, no fpm/nginx) ---
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
        php8.4-pgsql \
        php8.4-imagick \
        php8.4-redis \
        php8.4-pcov \
    && rm -rf /var/lib/apt/lists/*

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

# --- Layer 8: Trivy (Aqua Security signed apt repo) ---
RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    sh /tmp/lib/install-trivy.sh

# --- Layer 9: .NET SDK, Roslynator, Java, Maven, Gradle, SpotBugs + Find Security Bugs,
# Opengrep — every version pin lives in the script, not here. See
# docker/lib/install-static-analysis-toolchain.sh for the full rationale on each one,
# including why .NET is installed from Microsoft's script rather than its apt repo (the
# reason this target no longer uses the packages.microsoft.com apt repo it used to). ---
RUN --mount=type=bind,source=docker/lib,target=/tmp/lib,ro \
    OPENGREP_ENABLED=${OPENGREP_ENABLED} sh /tmp/lib/install-static-analysis-toolchain.sh

# PATH/JAVA_HOME can't be set from inside the script — it only affects the RUN step's
# own shell, not the image config — so the caller sets them here.
ENV PATH="${PATH}:/usr/local/dotnet-tools" \
    JAVA_HOME=/opt/java-home

# --- Layer 10: BFG Repo Cleaner (checksum- and signature-verified) ---
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

# --- Layer 11: Git config, entrypoint, final user switch ---
RUN git config --global --add safe.directory /workspace \
    && su ops -c "git config --global --add safe.directory /workspace"

ENV NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt

WORKDIR /workspace
COPY docker/ops/entrypoint.sh /entrypoint.sh
COPY docker/ops/collect-sboms.sh /usr/local/bin/collect-sboms.sh
COPY docker/ops/collect-static-analysis.sh /usr/local/bin/collect-static-analysis.sh
RUN chmod +x /entrypoint.sh /usr/local/bin/collect-sboms.sh /usr/local/bin/collect-static-analysis.sh

USER ops
ENTRYPOINT ["/entrypoint.sh"]
