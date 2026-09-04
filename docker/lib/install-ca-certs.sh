#!/bin/sh
# Installs every corporate/TLS-inspecting-proxy CA certificate exported by
# Export-HostCertificates (scripts/lib/Certificates.psm1) into the system trust
# store. Called identically at build time (bind-mounted into a Dockerfile RUN
# step) and at container start (docker/entrypoint.sh, the trivy-server command
# in docker-compose.yml). Runs under BusyBox sh in the composer:2 and
# aquasec/trivy base images, so no bash-only syntax.
set -eu

SRC_DIR="${1:-/host-certs}"

if [ ! -d "$SRC_DIR" ]; then
    exit 0
fi

# host-ca-bundle.crt is the combined bundle of the per-certificate files
# alongside it and is excluded here: installing it too would only duplicate
# every trust entry already installed from the per-certificate files.
CERT="$(find "$SRC_DIR" -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' -print -quit)"
if [ -z "$CERT" ]; then
    exit 0
fi

find "$SRC_DIR" -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
    -exec cp {} /usr/local/share/ca-certificates/ \;
update-ca-certificates
