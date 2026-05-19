# AppSec Scout — Operations Reference

## Build

```bash
# Build the application image (run from the repository root)
docker compose build

# Build and start all services in detached mode
docker compose up --build -d
```

## Start / stop

```bash
docker compose up -d          # Start all services (app, mysql, redis)
docker compose stop           # Stop without removing containers
docker compose down           # Stop and remove containers (data volumes are preserved)
docker compose down -v        # Stop and remove containers and volumes (destructive)
```

## Access the application

| URL | What |
|---|---|
| `http://localhost:8080/` | Filament admin panel (login page) |
| `http://localhost:8080/up` | Health check endpoint — returns `ok` when ready |

The port can be changed by setting `APP_PORT` in the environment before starting:

```bash
APP_PORT=9090 docker compose up -d
```

## Database migrations and seeding

Run these once after first boot and after any release that includes new migrations.

```bash
# Apply all pending migrations
docker compose exec app php artisan migrate --force

# Seed roles, permissions, and default configuration
docker compose exec app php artisan db:seed
```

## Create the first admin user

```bash
docker compose exec app php artisan tinker --execute="
\$user = \App\Models\User::factory()->create([
    'name'     => 'Admin',
    'email'    => 'admin@example.com',
    'password' => bcrypt('changeme'),
]);
\$user->assignRole('Admin');
echo 'Created: ' . \$user->email . PHP_EOL;
"
```

Change the password immediately after first login.

## Queue worker

The application uses Laravel queues for background jobs (audit log pruning, error log pruning). In the Docker setup the queue worker is managed by Supervisor inside the container and starts automatically. To inspect or restart it:

```bash
docker compose exec app supervisorctl status
docker compose exec app supervisorctl restart laravel-worker:*
```

## Logs

```bash
# Application log (real-time)
docker compose exec app tail -f storage/logs/laravel.log

# Container stdout (Nginx + PHP-FPM + Supervisor)
docker compose logs -f app

# MySQL slow query / error log
docker compose logs -f mysql
```

## Development: run the test suite

No local PHP installation is required. Tests run inside the official Composer Docker image.

```bash
# Code style check (Pint)
docker run --rm \
  -v "$(pwd)/app-laravel:/workspace" \
  -w /workspace \
  composer:2 \
  vendor/bin/pint --test

# Static analysis (PHPStan level 8 via Larastan)
docker run --rm \
  -v "$(pwd)/app-laravel:/workspace" \
  -w /workspace \
  composer:2 \
  vendor/bin/phpstan analyse --no-progress

# Feature and unit tests (Pest, SQLite in-memory)
docker run --rm \
  -v "$(pwd)/app-laravel:/workspace" \
  -w /workspace \
  composer:2 \
  vendor/bin/pest --no-coverage
```

All three commands must pass with zero errors before merging any change.

> **Corporate proxy / SSL inspection**: If your network intercepts HTTPS, prepend the CA certificate copy step to each Docker `run` command (see [install.md](install.md#corporate-proxy--ssl-inspection)).

## Scheduled tasks

Laravel's scheduler runs inside the container via a Supervisor-managed `schedule:work` process. Jobs registered in `app/Console/Kernel.php` (audit log pruning, error log pruning) fire automatically. No cron setup is needed on the host.

To trigger a specific job manually:

```bash
docker compose exec app php artisan schedule:run
docker compose exec app php artisan queue:work --once
```

## Upgrade procedure

```bash
git pull
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
```
