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

# public/build, public/css, and public/js are named Docker volumes so vendor/build
# output survives container recreation without being shadowed by the app-laravel
# bind mount. Docker only seeds a named volume from image content the first time it's
# created, so a rebuilt image's compiled assets would otherwise never reach a
# long-lived volume. Resync from the image's reference copy on every start instead.
# The volume mount points themselves can't be removed (only their contents), hence
# `-mindepth 1` rather than `rm -rf public/build` outright.
find public/build public/css public/js -mindepth 1 -delete
cp -r /opt/baked-assets/build/. public/build/
cp -r /opt/baked-assets/css/. public/css/
cp -r /opt/baked-assets/js/. public/js/

php artisan migrate --force
php artisan db:seed
php artisan appsec:bootstrap-admin --if-missing --name="${BOOTSTRAP_ADMIN_NAME:-Admin}" --email="${BOOTSTRAP_ADMIN_EMAIL:-admin@example.com}" --password="${BOOTSTRAP_ADMIN_PASSWORD:-changeme-now}"
php artisan permission:cache-reset
php artisan optimize:clear

chown -R www-data:www-data storage/ public/build public/css public/js

exec "$@"
