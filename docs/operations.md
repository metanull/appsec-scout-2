# AppSec Scout — Operations Reference

## Build

```bash
# Export host-trusted root/intermediate CAs first when using a corporate MITM proxy
./scripts/export-host-ca.sh   # Linux/macOS
./scripts/export-host-ca.ps1  # PowerShell on Windows

# Build the application image (run from the repository root)
docker compose build

# Build the development/CI image with dev dependencies included
APP_BUILD_TARGET=dev docker compose build app

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
| --- | --- |
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

No local PHP, Composer, Node.js, Java, Trivy, or BFG installation is required. Build the development image from this repository's Dockerfile, then run checks inside that container.

```bash
# Build the app image with dev dependencies once per dependency change
APP_BUILD_TARGET=dev docker compose build app

# Code style check (Pint)
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/pint --test

# Static analysis (PHPStan level 8 via Larastan)
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/phpstan analyse --no-progress

# Feature and unit tests (Pest, MySQL + Redis from Docker Compose)
APP_BUILD_TARGET=dev docker compose up -d mysql redis
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/pest --no-coverage
```

PowerShell equivalent:

```powershell
$env:APP_BUILD_TARGET = 'dev'
docker compose build app
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --no-progress
docker compose up -d mysql redis
docker compose run --rm app vendor/bin/pest --no-coverage
Remove-Item Env:\APP_BUILD_TARGET
```

All three commands must pass with zero errors before merging any change.

> **Corporate proxy / SSL inspection**: If your network intercepts HTTPS, use the Docker Compose override shown in [install.md](install.md#corporate-proxy--ssl-inspection) so the app image receives the corporate CA and proxy settings.

The helper scripts above export your locally trusted root/intermediate CAs into `.docker/certs/`, which is consumed automatically by the Docker build. Build-time proxy variables must still be set in your shell so Composer, npm, apt, and curl can reach the network through the corporate proxy.

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
