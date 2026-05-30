#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"
cd ..

export APP_BUILD_TARGET=dev

cleanup() {
  unset APP_BUILD_TARGET
}

trap cleanup EXIT

docker compose build app
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M

# Phase 1: SQLite (in-memory, no external services required)
docker compose run --rm \
  -e APP_ENV=testing \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  -e DB_URL= \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e MAIL_MAILER=array \
  app vendor/bin/pest --no-coverage

# Phase 2: MySQL (dedicated appsec_scout_test database; keeps the live appsec_scout DB untouched)
docker compose up -d mysql redis

# Wait for mysql health through the app dependency chain, then create the test database once.
docker compose run --rm app php -v >/dev/null

ROOT_PASSWORD="${DB_ROOT_PASSWORD:-rootpassword}"
SQL="CREATE DATABASE IF NOT EXISTS appsec_scout_test; GRANT ALL PRIVILEGES ON appsec_scout_test.* TO 'appsec_scout'@'%'; FLUSH PRIVILEGES;"
docker compose exec mysql mysql -uroot "--password=${ROOT_PASSWORD}" -e "${SQL}"

docker compose run --rm \
  -e APP_ENV=testing \
  -e DB_CONNECTION=mysql \
  -e DB_DATABASE=appsec_scout_test \
  -e DB_URL= \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e MAIL_MAILER=array \
  app vendor/bin/pest --no-coverage --configuration phpunit.mysql.xml

docker compose run --rm \
  -e APP_ENV=testing \
  -e DB_CONNECTION=mysql \
  -e DB_DATABASE=appsec_scout_test \
  -e DB_URL= \
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e MAIL_MAILER=array \
  app composer smoke