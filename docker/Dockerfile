# ── Stage 1: resolve Composer dependencies ───────────────────────────────────
FROM composer:2 AS composer-deps

WORKDIR /app

COPY app-laravel/composer.json app-laravel/composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY app-laravel/ ./

RUN composer dump-autoload --no-dev --optimize

# ── Stage 2: build Filament/Vite assets ──────────────────────────────────────
FROM node:22-alpine AS assets

WORKDIR /app

COPY app-laravel/package.json app-laravel/package-lock.json* ./

RUN npm ci --ignore-scripts

COPY app-laravel/ ./
COPY --from=composer-deps /app/vendor ./vendor

RUN npm run build

# ── Stage 3: production PHP-FPM image ────────────────────────────────────────
FROM php:8.4-fpm-bookworm AS final

# System packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    git \
    curl \
    unzip \
    ca-certificates \
    libicu-dev \
    libzip-dev \
    openjdk-21-jre-headless \
    && rm -rf /var/lib/apt/lists/*

# Install Trivy
RUN curl -sfL https://raw.githubusercontent.com/aquasecurity/trivy/main/contrib/install.sh \
    | sh -s -- -b /usr/local/bin

# Download BFG 1.15.0 jar (SHA-256 verified)
RUN mkdir -p /opt/bfg \
    && curl -fsSL \
       "https://repo1.maven.org/maven2/com/madgag/bfg/1.15.0/bfg-1.15.0.jar" \
       -o /opt/bfg/bfg.jar \
    && echo "9e3ea0b9c4f9f7e4c88df74faaede0f12ebbedb55d7d38cd3c3eb3b0fd3c6e4f  /opt/bfg/bfg.jar" \
       | sha256sum --check --status

# PHP extensions
RUN docker-php-ext-install pdo_mysql intl bcmath zip pcntl opcache

# Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# PHP-FPM config: use www-data (uid 33) — already exists in bookworm
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer-deps --chown=www-data:www-data /app ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
