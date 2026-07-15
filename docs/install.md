# AppSec Scout — Installation Guide

This guide takes a clean Docker host to a working first Admin login.

Relevant follow-up guides:

- [docs/admin.md](admin.md)
- [docs/operations.md](operations.md)
- [docs/security.md](security.md)
- [docs/architecture.md](architecture.md)

## Prerequisites

| Requirement | Version |
| --- | --- |
| Docker Engine | 24+ |
| Docker Compose | v2 plugin |
| Git | Current |
| PowerShell | 7+ (for the helper scripts under `scripts/`) |

No host PHP, Composer, Node.js, Java, Trivy, or BFG installation is required — everything runs
inside containers.

## Two `.env` Files

There are two separate environment files, read by two different things:

- **Root `.env`** (from `.env.example`) — read only by Docker Compose, for container-level
  settings: host ports, MySQL root/app credentials, proxy/TLS variables, Dependency-Track/Trivy
  configuration, and per-container resource limits.
- **`app-laravel/.env`** (from `app-laravel/.env.example`) — the Laravel application's own
  configuration. This one you do not need to create or edit for a first run: the `app` container's
  entrypoint copies `app-laravel/.env.example` to a persisted location on first boot, generates
  `APP_KEY` automatically, and re-copies its saved copy on every subsequent start.

If you set `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` in the root `.env`, they must match the same
keys in `app-laravel/.env` — the root file's comment block says so explicitly, since the `mysql`
container is initialized from the root file but the Laravel app connects using its own.

## Quick Start (recommended path)

```powershell
git clone https://github.com/metanull/appsec-scout-2.git
cd appsec-scout-2
cp .env.example .env
.\scripts\appsec-scout.ps1
```

`appsec-scout.ps1` builds the `app` image, starts every non-profiled service (see
[docs/architecture.md](architecture.md#runtime-topology)), waits for the app's health endpoint,
waits for Dependency-Track's one-shot bootstrap to finish, and opens `http://localhost:8080` in
the browser. On this first run the container entrypoint automatically generates `APP_KEY`, runs
migrations, seeds roles/permissions, and bootstraps an admin account
(`BOOTSTRAP_ADMIN_EMAIL`/`BOOTSTRAP_ADMIN_PASSWORD` from `app-laravel/.env`, defaulting to
`admin@example.com` / `a-changeme-now`) — there is nothing else to run by hand.

Use `.\scripts\appsec-scout.ps1 -Rebuild` for a clean slate (wipes containers, volumes, and data;
also re-exports any corporate CA certificates — see [Corporate Proxy and SSL Inspection](#corporate-proxy-and-ssl-inspection)
below) or `-Force` to rebuild the image without Docker's build cache. A plain re-run of the script
is enough to pick up code or dependency changes without losing data — it always rebuilds the image
first (cache permitting) before starting.

## Quick Start (manual, without the helper script)

Equivalent manual steps, useful when you want to see each step or don't have PowerShell:

```bash
git clone https://github.com/metanull/appsec-scout-2.git
cd appsec-scout-2
cp .env.example .env
docker compose up --build -d
docker compose wait dependencytrack-bootstrap
curl http://localhost:8080/up
```

No `key:generate`, `migrate`, `db:seed`, or `appsec:bootstrap-admin` step is needed — the
entrypoint already ran all of them by the time the app container reports healthy. Running
`appsec:bootstrap-admin` again yourself once a user already exists fails on purpose ("can only be
created when no users exist"); use `--if-missing` if you ever need to invoke it manually.

## Environment Variables

Root `.env` (Docker Compose only — see `.env.example` for the full, commented list):

| Variable | Default | Description |
| --- | --- | --- |
| `APP_PORT` | `8080` | Host port published for the `app` service |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `appsec_scout` / `appsec_scout` / `password` | Must match the same keys in `app-laravel/.env` |
| `DB_ROOT_PASSWORD` | `rootpassword` | MySQL root password (container-only, not used by the app) |
| `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` / `SSL_CERT_FILE` | — | Corporate proxy/TLS settings, shared by every container in the stack |
| `DTRACK_*` (`DTRACK_DB_*`, `DTRACK_API_PORT`, `DTRACK_FRONTEND_PORT`, `DTRACK_ADMIN_*`, `DTRACK_JAVA_MAX_HEAP`, ...) | see `.env.example` | Dependency-Track database, ports, admin bootstrap, and JVM sizing |
| `TRIVY_SERVER_URL` | `http://trivy-server:4954` | Shared self-hosted Trivy vulnerability DB server, used by Dependency-Track's Trivy analyzer and by SbomScan/StaticAnalysis |
| `MYSQL_CPUS`/`MYSQL_MEM_LIMIT`, `DTRACK_DB_CPUS`/`..._MEM_LIMIT`, `TRIVY_CPUS`/`..._MEM_LIMIT`, `OPS_CPUS`/`..._MEM_LIMIT` | see `.env.example` | Per-container CPU/memory limits, tunable for constrained hosts |

`app-laravel/.env` (the Laravel app — see `app-laravel/.env.example` for the full list):

| Variable | Default | Description |
| --- | --- | --- |
| `APP_KEY` | *(auto-generated)* | Set automatically by the entrypoint on first boot — no manual step |
| `APP_URL` | `http://localhost:8080` | External base URL |
| `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | `mysql` / `mysql` / `3306` / `appsec_scout` / `appsec_scout` / `password` | Must match the root `.env` values |
| `SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` | `redis` | All three use the `redis` container |
| `BOOTSTRAP_ADMIN_NAME`/`BOOTSTRAP_ADMIN_EMAIL`/`BOOTSTRAP_ADMIN_PASSWORD` | `admin` / `admin@example.com` / `a-changeme-now` | First-admin bootstrap identity, consumed by the entrypoint |
| `SKIP_APP_BOOTSTRAP` | unset | When `1`, skips the entrypoint's asset resync/migrate/seed/bootstrap block entirely — used by `invoke-check.ps1`/`invoke-fix.ps1` for one-off `docker compose run` invocations that shouldn't race the long-lived `app` container over shared volumes |

For a direct internet connection, leave the proxy/CA variables unset or empty — every container
then uses its own default OS/JRE CA store.

## Corporate Proxy and SSL Inspection

If outbound HTTPS is intercepted by a corporate proxy, `.\scripts\appsec-scout.ps1 -Rebuild`
exports the host's trusted CA certificates into `.docker/certs/` automatically (via
`Export-HostCertificates` in `scripts/lib/Certificates.psm1`) before rebuilding — there is no
separate script to run by hand. Set the proxy variables in the root `.env` (or the shell
environment used for the build) alongside it:

```powershell
$env:HTTP_PROXY = 'http://proxy.corp.example.com:3128'
$env:HTTPS_PROXY = 'http://proxy.corp.example.com:3128'
$env:NO_PROXY = 'localhost,127.0.0.1,mysql,redis'
.\scripts\appsec-scout.ps1 -Rebuild
```

The build copies exported `.crt` files into every stage so Composer, npm, apt, curl, `trivy-server`
(at container start, not build time), and the running app all trust the same CA chain. Only set
`SSL_CERT_FILE` when a custom CA bundle needs to be pointed to explicitly inside the container; if
unset or empty, outbound HTTPS uses the default CA store.

## Integration Credential Fields

Use `Admin -> System Credentials` for credentials shared by background jobs, or
`Profile -> Integrations` for credentials tied to your own user.

| Integration | Fields |
| --- | --- |
| Azure DevOps Advanced Security (Source) | `azdo.organization`, `azdo.pat` |
| Azure DevOps Repos (Source Control) | `azdo-repos.organization`, `azdo-repos.pat` |
| HCL AppScan on Cloud (Source) | `asoc.baseUrl`, `asoc.keyId`, `asoc.keySecret` |
| Detectify (Source) | `detectify.apiKey` |
| GitHub Issues (Work Tracker) | `github.token` |
| GitHub Repos (Source Control) | `github-repos.token` |
| Jira Cloud (Work Tracker) | `jira.host`, `jira.email`, `jira.api_token` |

For ASoC, `asoc.baseUrl` must match the region where the API key was created:

- US: `https://cloud.appscan.com/`
- EU: `https://eu.cloud.appscan.com/`

Dependency-Track's own API key (`dependencytrack.apiKey`) is provisioned automatically by the
`dependencytrack-bootstrap` container on first start — nothing to configure by hand.

## First Login

After the stack is up and the admin account is bootstrapped (see [Quick Start](#quick-start-recommended-path)):

1. Open `http://localhost:8080/`.
2. Sign in with the bootstrap admin's email and password.
3. Complete mandatory multi-factor enrollment (an authenticator app TOTP code) — Filament's own
   panel-level multi-factor feature enforces this before any protected page is reachable; you're
   redirected to the setup page automatically until it's done.
4. Change the bootstrap password immediately if you used the default value.

Disabled users are logged out automatically and cannot access the panel or web routes until
re-enabled by an Admin.

## Health Check

```bash
curl http://localhost:8080/up
# ok
```

Expected Docker state (`docker compose ps`): `mysql`, `redis`, `app`, `dependencytrack-postgres`,
`dependencytrack-apiserver`, `dependencytrack-frontend`, and `trivy-server` all `Up`/healthy;
`dependencytrack-cacerts-init`, `trivy-token-init`, and `dependencytrack-bootstrap` exited
successfully (they are one-shot containers). See [docs/architecture.md](architecture.md#runtime-topology)
for what each service does.
