#!/bin/sh
set -e

cd /var/www/html

# Runtime CA trust: images pulled from GHCR can never be rebuilt, so a corporate/
# TLS-inspecting proxy CA must be installable when the container starts, not only
# when the image is built (the Dockerfiles still bake certs for local builds —
# the build itself needs them for Composer/npm/apt). docker-compose.yml mounts
# ./.docker/certs (populated by Export-HostCertificates, scripts/lib/
# Certificates.psm1) at /host-certs; this is a silent no-op when the mount is
# absent or empty. host-ca-bundle.crt is excluded because it is the combined
# bundle of the per-certificate files alongside it and would only duplicate
# every trust entry — the same exclusion the Dockerfiles apply at build time.
# The static-analysis-collector's Temurin JDK truststore needs no separate
# handling here: its $JAVA_HOME/lib/security/cacerts symlinks to the store the
# adoptium-ca-certificates package maintains through an update-ca-certificates
# hook (/etc/ca-certificates/update.d/adoptium-cacerts), so the invocation
# below regenerates the JVM store too — verified empirically, a runtime-added
# CA shows up in `keytool -list` right after.
if [ -d /host-certs ] && [ -n "$(find /host-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' -print -quit 2>/dev/null)" ]; then
    find /host-certs -maxdepth 1 -type f -name '*.crt' ! -name 'host-ca-bundle.crt' \
        -exec cp {} /usr/local/share/ca-certificates/ \;
    update-ca-certificates
fi

# Immutable cloud boot: the image content is authoritative and configuration
# comes exclusively from the real environment. Skips the persisted-.env dance,
# runtime composer install, asset resync, migrate/seed/admin bootstrap, and
# chown — all of which are wrong for immutable, possibly multi-replica deploys
# (migration races, package-registry egress on cold start, chown failing on
# mounted shares). Migrations belong to a dedicated job (which can run
# `php artisan migrate --force` through this same entrypoint as the container
# command); APP_BOOT_MIGRATE=1 is the guarded-single-replica alternative that
# migrates on boot.
if [ "${APP_IMMUTABLE_BOOT:-0}" = "1" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_IMMUTABLE_BOOT=1 requires APP_KEY in the environment: nothing may generate a key in this mode, and booting without one would encrypt new secrets with a key that is lost on the next boot." >&2
        exit 1
    fi

    if [ "${APP_BOOT_MIGRATE:-0}" = "1" ]; then
        php artisan migrate --force
    fi

    exec "$@"
fi

STORED_ENV="storage/app/private/.env"

mkdir -p "$(dirname "$STORED_ENV")"

if [ -f "$STORED_ENV" ]; then
    cp "$STORED_ENV" .env
else
    cp .env.example "$STORED_ENV"
    cp "$STORED_ENV" .env
    # An APP_KEY supplied through the container environment (e.g. from a cloud
    # secret store) takes precedence over .env in Laravel and is the single key
    # of record — never generate a competing key into the persisted .env, or a
    # later loss of the env var would silently decrypt with the wrong key.
    if [ -z "${APP_KEY:-}" ]; then
        php artisan key:generate --force
        cp .env "$STORED_ENV"
    fi
fi

composer install --optimize-autoloader

# SKIP_APP_BOOTSTRAP is set by invoke-check.ps1/invoke-fix.ps1 for one-off `docker
# compose run` commands (Pint, PHPStan, Pest, Composer). Those never serve HTTP and
# don't need migrations, seeding, or the shared asset volumes — and running this
# block concurrently with the long-lived `app` container racing on the same named
# volumes (app_storage, app_public_build/css/js) is what caused intermittent
# "Utime failed: Operation not permitted" / "No such file or directory" errors on
# the live app while a check was running in the background.
if [ "${SKIP_APP_BOOTSTRAP:-0}" != "1" ]; then
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
fi

exec "$@"
