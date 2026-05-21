# AppSec Scout — Installation Guide

## Prerequisites

| Requirement | Version |
| --- | --- |
| Docker Engine | 24+ |
| Docker Compose | v2 (plugin) |
| MySQL | 8.0+ (provided via Docker) |

No local PHP, Composer, Node.js, Java, Trivy, or BFG installation is required. The application image builds and carries the runtime tools it needs.

## Quick start

```bash
# 1. Clone the repository
git clone https://github.com/metanull/appsec-scout-2.git
cd appsec-scout-2

# 2. Copy environment file and fill in secrets
cp app-laravel/.env.example .env
$EDITOR .env          # Set APP_KEY and replace default local passwords before shared use.

# 3. Generate the Laravel app key (if APP_KEY is empty)
#    This can be done inside the container after first boot:
docker compose run --rm app php artisan key:generate --show
# Copy the output into .env as APP_KEY=...

# 4. Build and start all services
docker compose up --build -d

# 5. Run database migrations
docker compose exec app php artisan migrate --force

# 6. Seed roles and permissions
docker compose exec app php artisan db:seed

# 7. Create the first admin user
docker compose exec app php artisan tinker
# >>> \App\Models\User::factory()->create(['email' => 'admin@example.com', 'password' => bcrypt('changeme')]);
# >>> $user->assignRole('Admin');
```

## Environment variables

| Variable | Required | Default | Description |
| --- | --- | --- | --- |
| `APP_KEY` | Yes | — | Laravel encryption key (run `php artisan key:generate`) |
| `APP_URL` | Yes | `http://localhost:8080` | Public URL of the application |
| `DB_PASSWORD` | Yes | `password` | MySQL password for the `appsec_scout` user |
| `DB_ROOT_PASSWORD` | No | `rootpassword` | MySQL root password |
| `HTTP_PROXY` | No | — | Corporate HTTP proxy URL |
| `HTTPS_PROXY` | No | — | Corporate HTTPS proxy URL |
| `NO_PROXY` | No | — | Comma-separated list of hosts to bypass proxy |
| `SSL_CERT_FILE` | No | — | Path to custom CA bundle inside the container |

## Corporate proxy / SSL inspection

If your network uses SSL inspection (e.g. Netskope, Zscaler):

1. Export the host trust store into the repo-local Docker cert directory.
2. Set `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` in your shell.
3. Build the image normally with Docker Compose.

PowerShell on Windows:

```powershell
./scripts/export-host-ca.ps1
```

POSIX shell on Linux/macOS:

```sh
./scripts/export-host-ca.sh
```

Both helpers write PEM `.crt` files under `.docker/certs/`. The Docker build copies those certificates into every stage before Composer, npm, curl, or the final app image make outbound TLS connections, so `docker compose build` and the running container trust the same local root CAs.

Set the proxy variables in the same shell before building:

```bash
export HTTP_PROXY=http://proxy.corp.example.com:3128
export HTTPS_PROXY=http://proxy.corp.example.com:3128
export NO_PROXY=localhost,127.0.0.1,mysql,redis
docker compose build
```

```powershell
$env:HTTP_PROXY = 'http://proxy.corp.example.com:3128'
$env:HTTPS_PROXY = 'http://proxy.corp.example.com:3128'
$env:NO_PROXY = 'localhost,127.0.0.1,mysql,redis'
docker compose build
```

If you prefer a manual path, you can still place one or more PEM-encoded `.crt` files under `.docker/certs/` yourself before building.

Limitations:

- This repo-local flow fixes TLS inside the Docker build and inside the application container.
- It does not reconfigure Docker Desktop or the Docker daemon's own trust store for image pulls or registry access. If base image pulls fail before the Dockerfile starts, you must also configure Docker Desktop's proxy and CA trust on the host.

```yaml
# docker-compose.override.yml
services:
  app:
    volumes:
      - ./corporate-ca.crt:/etc/ssl/corporate-ca.crt:ro
    environment:
      SSL_CERT_FILE: /etc/ssl/corporate-ca.crt
      HTTPS_PROXY: http://proxy.corp.example.com:3128
```

## Health check

```bash
curl http://localhost:8080/up
# → ok
```

## Included triage tools

| Tool | Location |
| --- | --- |
| Trivy (SBOM/vulnerability scanner) | `/usr/bin/trivy` |
| BFG Repo Cleaner 1.15.0 | `/opt/bfg/bfg.jar` |
| Git | `/usr/bin/git` |
| Java 21 (JRE) | `/usr/bin/java` |

## Security scan baseline

Run `trivy image appsec-scout:latest` after building to capture the initial SBOM.
Target: zero HIGH/CRITICAL CVEs (enforcement deferred to M6).
