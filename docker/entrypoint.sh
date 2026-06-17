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

composer install --optimize-autoloader
php artisan migrate --force
php artisan db:seed
php artisan appsec:bootstrap-admin --if-missing --name="${BOOTSTRAP_ADMIN_NAME:-Admin}" --email="${BOOTSTRAP_ADMIN_EMAIL:-admin@example.com}" --password="${BOOTSTRAP_ADMIN_PASSWORD:-changeme-now}"
php artisan permission:cache-reset
php artisan optimize:clear

exec "$@"
