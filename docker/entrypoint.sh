#!/bin/sh
set -e

cd /var/www/html

STORED_ENV="storage/app/private/.env"

mkdir -p "$(dirname "$STORED_ENV")"

if [ -f "$STORED_ENV" ]; then
    cp "$STORED_ENV" .env
else
    cp .env.example "$STORED_ENV"
    cp "$STORED_ENV" .env
    php artisan key:generate --force
    cp .env "$STORED_ENV"
fi

exec "$@"
