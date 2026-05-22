# AppSec Scout — Installation Guide

This guide takes a clean Docker host to a working first Admin login without Tinker.

Relevant follow-up guides:

- [docs/admin.md](admin.md)
- [docs/operations.md](operations.md)
- [docs/security.md](security.md)
- [docs/architecture.md](architecture.md)

## Scope

This installation flow documents the implemented M1 to M6 Laravel application.

- The M6 Admin UI includes user lifecycle management, integration settings, queue and schedule visibility, system credentials, audit logs, and error logs.
- Defender for Cloud remains deferred from M6 and is not part of the supported first-run flow.
- CI does not enforce image vulnerability or SBOM gates. Local operators may run optional manual checks if needed.

## Prerequisites

| Requirement | Version |
| --- | --- |
| Docker Engine | 24+ |
| Docker Compose | v2 plugin |
| Git | Current |

No host PHP, Composer, Node.js, Java, Trivy, or BFG installation is required.

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/metanull/appsec-scout-2.git
cd appsec-scout-2

# 2. Copy the environment file and set local secrets
cp app-laravel/.env.example .env

# 3. Generate an app key if APP_KEY is empty
docker compose run --rm app php artisan key:generate --show

# 4. Build and start the stack
docker compose up --build -d

# 5. Apply database migrations and seed roles and permissions
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed

# 6. Bootstrap the first admin account
docker compose exec app php artisan appsec:bootstrap-admin \
  --name="Admin" \
  --email="admin@example.com" \
  --password="changeme-now"

# 7. Confirm the app is healthy
curl http://localhost:8080/up
```

PowerShell equivalent:

```powershell
Copy-Item app-laravel/.env.example .env
docker compose run --rm app php artisan key:generate --show
docker compose up --build -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed
docker compose exec app php artisan appsec:bootstrap-admin --name="Admin" --email="admin@example.com" --password="changeme-now"
Invoke-WebRequest http://localhost:8080/up
```

## Environment Variables

| Variable | Required | Default | Description |
| --- | --- | --- | --- |
| `APP_KEY` | Yes | — | Laravel application key |
| `APP_URL` | Yes | `http://localhost:8080` | External base URL |
| `APP_PORT` | No | `8080` | Host port published by Docker Compose |
| `DB_DATABASE` | No | `appsec_scout` | MySQL database name |
| `DB_USERNAME` | No | `appsec_scout` | MySQL application user |
| `DB_PASSWORD` | Yes | `password` | MySQL application password |
| `DB_ROOT_PASSWORD` | No | `rootpassword` | MySQL root password |
| `HTTP_PROXY` | No | — | Corporate HTTP proxy |
| `HTTPS_PROXY` | No | — | Corporate HTTPS proxy |
| `NO_PROXY` | No | — | Proxy bypass list |
| `SSL_CERT_FILE` | No | — | Custom CA bundle path inside the container |

## Corporate Proxy And SSL Inspection

If outbound HTTPS is intercepted by a corporate proxy:

1. Export the host trust store into `.docker/certs/`.
2. Set `HTTP_PROXY`, `HTTPS_PROXY`, and `NO_PROXY` in the shell used for Docker builds.
3. Build and start the app normally.

Windows PowerShell:

```powershell
./scripts/export-host-ca.ps1
$env:HTTP_PROXY = 'http://proxy.corp.example.com:3128'
$env:HTTPS_PROXY = 'http://proxy.corp.example.com:3128'
$env:NO_PROXY = 'localhost,127.0.0.1,mysql,redis'
docker compose build
```

Linux or macOS:

```bash
./scripts/export-host-ca.sh
export HTTP_PROXY=http://proxy.corp.example.com:3128
export HTTPS_PROXY=http://proxy.corp.example.com:3128
export NO_PROXY=localhost,127.0.0.1,mysql,redis
docker compose build
```

The build copies exported PEM `.crt` files into every stage so Composer, npm, apt, curl, and the running app trust the same CA chain.

## First Login

After bootstrapping the first admin:

1. Open `http://localhost:8080/`.
2. Sign in with the email and password passed to `appsec:bootstrap-admin`.
3. Complete mandatory two-factor enrollment at `/user/two-factor-setup`.
4. After confirmation, the Filament application shell becomes available.
5. Change the bootstrap password immediately if you used a temporary value.

Disabled users are logged out automatically and cannot access the panel or web routes until re-enabled by an Admin.

## Health Check

```bash
curl http://localhost:8080/up
# ok
```

Expected Docker state:

- `mysql` healthy
- `redis` running
- `app` healthy

## Runtime Topology

The Compose stack runs three services:

- `app`: nginx, php-fpm, Laravel scheduler worker, and queue worker under Supervisor
- `mysql`: MySQL 8 storage
- `redis`: queue, cache, and session backend

The production-style `app` service runs with:

- read-only root filesystem
- all Linux capabilities dropped
- explicit writable mounts for Laravel storage and tmpfs-backed runtime paths
- port `8080` so no privileged bind capability is required

## Included Triage Tools

| Tool | Location |
| --- | --- |
| Trivy | `/usr/bin/trivy` |
| BFG Repo Cleaner 1.15.0 | `/opt/bfg/bfg.jar` |
| Git | `/usr/bin/git` |
| Java 21 JRE | `/usr/bin/java` |

These tools are used by supported triage commands and the Operations page action that queues a Trivy DB update.
