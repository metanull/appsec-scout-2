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
docker compose run --rm app vendor/bin/pest --no-coverage
docker compose run --rm app composer smoke