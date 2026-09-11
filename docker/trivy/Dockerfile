# Bakes the runtime CA-trust script into the image so a pulled deployment doesn't need the repo checkout.
FROM aquasec/trivy:latest

COPY docker/lib/install-ca-certs.sh /usr/local/bin/install-ca-certs.sh
