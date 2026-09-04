#!/bin/sh
# Installs Trivy from Aqua Security's signed apt repo. Used by both the collector
# target (docker/Dockerfile) and the ops target, so the same version lands in both —
# scans against the shared trivy-server container (docker-compose.yml), never
# downloads its own vulnerability database.
set -eu

wget -qO- https://aquasecurity.github.io/trivy-repo/deb/public.key \
    | gpg --dearmor -o /usr/share/keyrings/trivy.gpg
echo "deb [signed-by=/usr/share/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
    > /etc/apt/sources.list.d/trivy.list
apt-get update
apt-get install -y --no-install-recommends trivy
rm -rf /var/lib/apt/lists/*
