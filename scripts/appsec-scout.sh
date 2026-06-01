#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")"
cd ..

export APP_BUILD_TARGET=dev

if ! docker compose ps >/dev/null 2>&1; then
  echo "Docker compose is not running. Please start Docker and try again." >&2
  exit 1
fi

cp app-laravel/.env.example .env

APP_KEY=$(docker compose run --rm app php artisan key:generate --show 2>/dev/null)
if [ -z "$APP_KEY" ]; then
  echo "Failed to generate application key. Please check your Docker setup and try again." >&2
  exit 1
fi

sed "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" .env > .env.tmp
mv .env.tmp .env

if ! docker compose up --build -d; then
  echo "Failed to start Docker containers. Please check your Docker setup and try again." >&2
  exit 1
fi

if ! docker compose ps; then
  echo "Failed to list Docker containers. Please check your Docker setup and try again." >&2
  exit 1
fi

if ! docker compose exec app php artisan migrate --force; then
  echo "Failed to run database migrations. Please check your Docker setup and try again." >&2
  exit 1
fi

if ! docker compose exec app php artisan db:seed; then
  echo "Failed to seed the database. Please check your Docker setup and try again." >&2
  exit 1
fi

if ! docker compose exec app php artisan appsec:bootstrap-admin \
  --if-missing \
  --name="Admin" \
  --email="admin@example.com" \
  --password="changeme-now"; then
  echo "Failed to bootstrap admin user. Please check your Docker setup and try again." >&2
  exit 1
fi

if [ -f .credentials.json ]; then
  if ! docker compose cp .credentials.json app:/var/www/html/storage/app/private/credentials.json; then
    echo "Failed to copy .credentials.json into the app container." >&2
    exit 1
  fi

  if ! docker compose exec app php artisan credentials:system:import /var/www/html/storage/app/private/credentials.json; then
    echo "Failed to import system credentials from copied JSON file." >&2
    exit 1
  fi

  echo "Imported system credentials from .credentials.json"
else
  echo "No .credentials.json found. Skipping credential import."
fi

if ! curl -fsS http://localhost:8080/up >/dev/null; then
  echo "Failed to assert that the application is up." >&2
  exit 1
fi

unset APP_BUILD_TARGET