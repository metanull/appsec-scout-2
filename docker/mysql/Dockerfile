# Bakes the test-DB init script into the image so a pulled deployment doesn't need the repo checkout.
FROM mysql:8.0

COPY docker/mysql-init.sql /docker-entrypoint-initdb.d/01-test-db.sql
