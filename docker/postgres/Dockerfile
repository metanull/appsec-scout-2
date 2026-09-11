# Bakes the test-DB init script into the image so a pulled deployment doesn't need the repo checkout.
FROM postgres:16-alpine

COPY docker/postgres-init.sql /docker-entrypoint-initdb.d/01-test-db.sql
