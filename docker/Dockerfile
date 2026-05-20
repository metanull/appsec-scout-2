# syntax=docker/dockerfile:1

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

RUN composer dump-autoload --no-dev --optimize --no-scripts

# ── Stage 2: build Filament/Vite assets ──────────────────────────────────────
FROM node:22-alpine AS assets

WORKDIR /app

COPY app-laravel/package.json app-laravel/package-lock.json* ./

RUN npm ci --ignore-scripts

COPY app-laravel/ ./
COPY --from=composer-deps /app/vendor ./vendor

RUN npm run build

# ── Stage 3: production PHP-FPM image ────────────────────────────────────────
FROM php:8.4-fpm-trixie AS final

ARG BFG_VERSION=1.15.0
ARG BFG_SHA256=dfe2885adc2916379093f02a80181200536856c9a987bf21c492e452adefef7a

# System packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    git \
    curl \
    gpg \
    unzip \
    ca-certificates \
    libicu-dev \
    libzip-dev \
    && install -d -m 0755 /etc/apt/keyrings \
    && curl -fsSL https://aquasecurity.github.io/trivy-repo/deb/public.key \
        | gpg --dearmor -o /etc/apt/keyrings/trivy.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
        > /etc/apt/sources.list.d/trivy.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        openjdk-21-jre-headless \
        trivy \
    && rm -rf /var/lib/apt/lists/*

# Download BFG 1.15.0 jar (SHA-256 verified)
RUN mkdir -p /opt/bfg \
    && curl -fsSL \
        "https://repo1.maven.org/maven2/com/madgag/bfg/${BFG_VERSION}/bfg-${BFG_VERSION}.jar" \
        -o /opt/bfg/bfg.jar \
    && echo "${BFG_SHA256}  /opt/bfg/bfg.jar" \
        | sha256sum --check --status

# PHP extensions
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql intl bcmath zip pcntl opcache

# Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# PHP-FPM config: use www-data (uid 33) — already exists in Debian-based PHP images
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer-deps /app ./
COPY --from=assets /app/public/build ./public/build

RUN touch .env \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# ── Stage 4: development / CI image ─────────────────────────────────────────
FROM final AS dev

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts
