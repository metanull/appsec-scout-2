# Bakes the test-DB init script into the image so a pulled deployment doesn't need the repo checkout.
FROM mysql:8.0

# Pick up patched OS packages before the CI Trivy publish gate scans this image — pinning
# exact microdnf package versions would go stale and defeat the point of the upgrade (same
# reasoning as the apt-get/apk upgrade steps in docker/Dockerfile, see .hadolint.yaml).
RUN microdnf update -y && microdnf clean all

COPY docker/mysql-init.sql /docker-entrypoint-initdb.d/01-test-db.sql
