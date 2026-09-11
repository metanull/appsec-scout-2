# Bakes the runtime CA-trust script into the image so a pulled deployment doesn't need the repo checkout.
FROM aquasec/trivy:latest

# Pick up patched OS packages before the CI Trivy publish gate scans this image — pinning
# exact apk package versions would go stale and defeat the point of the upgrade (same
# reasoning as the apt-get/apk upgrade steps in docker/Dockerfile, see .hadolint.yaml).
RUN apk update && apk upgrade --no-cache

COPY docker/lib/install-ca-certs.sh /usr/local/bin/install-ca-certs.sh
