cd "$(dirname "$0")"
cd ..

export APP_BUILD_TARGET=dev

cp app-laravel/.env.example .env

APP_KEY=$(docker compose run --rm app php artisan key:generate --show 2>/dev/null)
docker compose up --build -d
docker compose ps

docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed

docker compose exec app php artisan appsec:bootstrap-admin \
  --name="Admin" \
  --email="admin@example.com" \
  --password="changeme-now"

curl http://localhost:8080/up

unset APP_BUILD_TARGET