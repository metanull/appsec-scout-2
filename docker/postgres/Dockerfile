# Bakes the test-DB init script into the image so a pulled deployment doesn't need the repo checkout.
FROM postgres:16-alpine

# Pick up patched OS packages before the CI Trivy publish gate scans this image — pinning
# exact apk package versions would go stale and defeat the point of the upgrade (same
# reasoning as the apt-get/apk upgrade steps in docker/Dockerfile, see .hadolint.yaml).
RUN apk update && apk upgrade --no-cache

COPY docker/postgres-init.sql /docker-entrypoint-initdb.d/01-test-db.sql
