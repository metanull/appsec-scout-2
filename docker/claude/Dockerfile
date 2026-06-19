# syntax=docker/dockerfile:1

FROM node:22-slim

ARG HTTP_PROXY=""
ARG HTTPS_PROXY=""
ARG NO_PROXY=""

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

COPY .docker/certs/ /tmp/appsec-scout-certs/

# Install base packages and custom CA certs in one layer (same pattern as app Dockerfile)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        ca-certificates \
        curl \
        jq \
    && if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
         find /tmp/appsec-scout-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
             -exec cp {} /usr/local/share/ca-certificates/ \; ; \
         update-ca-certificates; \
       fi \
    && rm -rf /var/lib/apt/lists/*

# GitHub CLI — for PR creation at the end of autonomous tasks
RUN curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

# npm install with corp-proxy-aware cert handling (mirrors assets stage in app Dockerfile)
RUN if [ -f /tmp/appsec-scout-certs/host-ca-bundle.crt ]; then \
        export NODE_EXTRA_CA_CERTS=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
        export NPM_CONFIG_CAFILE=/tmp/appsec-scout-certs/host-ca-bundle.crt; \
    fi; \
    npm install -g @anthropic-ai/claude-code

# Non-root user — --dangerously-skip-permissions refuses to run as root
RUN useradd -m -s /bin/bash claude \
    && mkdir -p /home/claude/.claude /workspace \
    && chown claude:claude /home/claude/.claude /workspace

RUN git config --global --add safe.directory /workspace \
    && su claude -c "git config --global --add safe.directory /workspace"

# Node trusts the system CA store, which now includes any installed corporate certs
ENV NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt

WORKDIR /workspace
COPY docker/claude/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER claude
ENTRYPOINT ["/entrypoint.sh"]
