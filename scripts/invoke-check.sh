#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"
cd ..

ENV_TESTING="app-laravel/.env.testing"
ENV_EXAMPLE="app-laravel/.env.testing.example"

# Ensure .env.testing exists; copy from the committed example when it is missing.
if [ ! -f "$ENV_TESTING" ]; then
    cp "$ENV_EXAMPLE" "$ENV_TESTING"
fi

# Generate APP_KEY when absent (equivalent to artisan key:generate; done before
# the Docker build so the key is stable across all phases of this run).
if ! grep -qE '^APP_KEY=.+' "$ENV_TESTING"; then
    key="base64:$(openssl rand -base64 32 | tr -d '\n')"
    sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" "$ENV_TESTING"
fi

# Build the -e argument array from .env.testing so that every docker compose run
# command below receives the test environment without hardcoded values.
test_env_args=()
while IFS= read -r line; do
    [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && test_env_args+=("-e" "$line")
done < "$ENV_TESTING"

export APP_BUILD_TARGET=dev

cleanup() {
    unset APP_BUILD_TARGET
}
trap cleanup EXIT

docker compose build app --quiet
docker compose run --rm app vendor/bin/pint --test --quiet
docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M -q

# Phase 1: SQLite (in-memory).
# phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory: via force="true",
# overriding the mysql settings that arrive from .env.testing.
docker compose run --rm "${test_env_args[@]}" app vendor/bin/pest --no-coverage --compact

# Phase 2: MySQL (dedicated appsec_scout_test database).
# The database is created automatically by docker/mysql-init.sql on first MySQL
# start; artisan migrate:fresh handles the schema — no manual SQL required.
docker compose up -d mysql redis

docker compose run --rm "${test_env_args[@]}" app php artisan migrate:fresh --force

docker compose run --rm "${test_env_args[@]}" app vendor/bin/pest --no-coverage --configuration phpunit.mysql.xml --compact

docker compose run --rm "${test_env_args[@]}" app composer smoke
