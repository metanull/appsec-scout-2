# Build image for Anthropic's OSS Scanner (https://github.com/anthropics/oss-scanner).
#
# The scanner builds this file with the repository root as the build context and no --target, then audits the
# result with no network access, so everything the app and its tests need is fetched here. docker/Dockerfile is not
# reused: its stages expect Compose (MySQL, Redis, an entrypoint that bootstraps on start) and BuildKit bind mounts.
#
#   /src               the checkout
#   /src/app-laravel   the Laravel 13 + Filament 5 app, built: vendor/ with dev dependencies, public/build/, a .env
#   /src/app-laravel/database/scan.sqlite   the running site's database (Pest keeps its own sqlite :memory:)
#
# `scout-start` serves the panel on http://127.0.0.1:8000/ (database sessions, sync queue, file cache: no Redis).
# Seeded accounts, password `password`, TOTP enrolled with the secret in $SCAN_TOTP_SECRET (`scout-totp` prints
# the current code): reader@, triage@, plan@, sync@, admin@example.com, one per role.
#
# Kept in step with docker/Dockerfile's php-runtime stage: same PHP minor, same extensions.
FROM php:8.4-cli-trixie

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    LANG=C.UTF-8 \
    SCAN_TOTP_SECRET=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip procps sqlite3 libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl bcmath zip pcntl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis opcache \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# docker/Dockerfile builds the assets on Node 22.
COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version

# Errors are logged, not shown, as in production.
RUN { echo 'memory_limit=512M'; \
      echo 'error_reporting=E_ALL'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
    } > /usr/local/etc/php/conf.d/oss-scanner.ini

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src/app-laravel

# A local .env (sqlite file, sync queue, file cache: no MySQL or Redis), then dependencies with dev tools (Pest,
# PHPStan, Pint) so the suite runs offline, as CI installs them, then the assets.
RUN cp .env.example .env \
    && sed -i -e 's#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#' \
              -e 's#^DB_DATABASE=.*#DB_DATABASE=/src/app-laravel/database/scan.sqlite#' \
              -e 's#^QUEUE_CONNECTION=.*#QUEUE_CONNECTION=sync#' \
              -e 's#^CACHE_STORE=.*#CACHE_STORE=file#' \
              -e 's#^APP_URL=.*#APP_URL=http://127.0.0.1:8000#' .env \
    && mkdir -p bootstrap/cache storage/app/private storage/app/public storage/app/triage \
             storage/framework/cache/data storage/framework/sessions storage/framework/testing \
             storage/framework/views storage/logs \
    && composer install --no-progress --prefer-dist --no-scripts \
    && composer dump-autoload --optimize \
    && npm ci \
    && npm run build

# A runnable local site on the sqlite file the .env above names. Entra SSO stays off, as it ships.
RUN touch database/scan.sqlite \
    && php artisan key:generate --force \
    && php artisan migrate --force --seed \
    && php artisan filament:assets \
    && php artisan tinker --execute="require '/src/.oss-scanner/seed-scan-users.php';"

RUN install -m 0755 /src/.oss-scanner/bin/scout-start /src/.oss-scanner/bin/scout-totp /usr/local/bin/

# Smoke-test the site and run part of the suite, without letting a failing test abort the build.
RUN set -e; scout-start; \
    curl -fsS -o /dev/null http://127.0.0.1:8000/login; \
    scout-totp; \
    vendor/bin/pest --no-coverage tests/Feature/Auth tests/Unit || echo "WARNING: Pest reported failures"; \
    pkill -f "[a]rtisan serve" || true

# Keep build products out of the checkout's `git status`.
RUN printf 'app-laravel/.env\napp-laravel/database/scan.sqlite\n' >> /src/.git/info/exclude 2>/dev/null || true
