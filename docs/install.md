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

## Choosing an Install Track

There are two supported ways to obtain the application images; everything else (services,
volumes, configuration, first login) is identical between them:

- **Build from source** (default, and the only mode for development): the Quick Start below —
  `docker compose` builds the `app`, `collector`, and `static-analysis-collector` images from
  the Dockerfiles in this repository.
- **Run prebuilt images**: the stack runs the exact Trivy-gated images CI publishes to the
  GitHub Container Registry — no build toolchain, no compilation, faster first start. See
  [Prebuilt Container Images](#prebuilt-container-images) for the one-line switch.

Both tracks start from a clone of this repository (it carries the compose files, helper
scripts, and `.env.example`) and support the same corporate proxy setup — see
[Corporate Proxy and SSL Inspection](#corporate-proxy-and-ssl-inspection) for which steps
apply to which track.

## Two `.env` Files

There are two separate environment files, read by two different things:

- **Root `.env`** (from `.env.example`) — read only by Docker Compose, for container-level
  settings: host ports, database credentials, the optional database-engine switch, proxy/TLS
  variables, Dependency-Track/Trivy configuration, and per-container resource limits.
- **`app-laravel/.env`** (from `app-laravel/.env.example`) — the Laravel application's own
  configuration. This one you do not need to create or edit for a first run: the `app` container's
  entrypoint copies `app-laravel/.env.example` to a persisted location on first boot, generates
  `APP_KEY` automatically (unless one is supplied through the container environment — see
  [Cloud / Immutable Boot](#cloud--immutable-boot)), and re-copies its saved copy on every
  subsequent start.

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
| `DB_ROOT_PASSWORD` | `rootpassword` | MySQL root password (container-only, not used by the app; unused by the PostgreSQL engine) |
| `COMPOSE_FILE` | unset | Uncomment `COMPOSE_FILE=docker-compose.yml;docker-compose.pgsql.yml` to run the stack on PostgreSQL 16 instead of MySQL 8 — see "Choosing the database engine" below |
| `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` / `SSL_CERT_FILE` | — | Corporate proxy/TLS settings, shared by every container in the stack |
| `DTRACK_*` (`DTRACK_DB_*`, `DTRACK_API_PORT`, `DTRACK_FRONTEND_PORT`, `DTRACK_ADMIN_*`, `DTRACK_JAVA_MAX_HEAP`, ...) | see `.env.example` | Dependency-Track database, ports, admin bootstrap, and JVM sizing |
| `TRIVY_SERVER_URL` | `http://trivy-server:4954` | Shared self-hosted Trivy vulnerability DB server, used by Dependency-Track's Trivy analyzer and by SbomScan/StaticAnalysis |
| `MYSQL_CPUS`/`MYSQL_MEM_LIMIT`, `DTRACK_DB_CPUS`/`..._MEM_LIMIT`, `TRIVY_CPUS`/`..._MEM_LIMIT`, `OPS_CPUS`/`..._MEM_LIMIT` | see `.env.example` | Per-container CPU/memory limits, tunable for constrained hosts |

`app-laravel/.env` (the Laravel app — see `app-laravel/.env.example` for the full list):

| Variable | Default | Description |
| --- | --- | --- |
| `APP_KEY` | *(auto-generated)* | Set automatically by the entrypoint on first boot — no manual step. If supplied through the container environment instead (e.g. from a secret store), it takes precedence and no key is ever generated |
| `APP_URL` | `http://localhost:8080` | External base URL |
| `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | `mysql` / `mysql` / `3306` / `appsec_scout` / `appsec_scout` / `password` | Must match the root `.env` values |
| `SESSION_DRIVER` | `database` | Sessions live in the primary database so that disabling a user revokes its live sessions immediately |
| `CACHE_STORE`/`QUEUE_CONNECTION` | `redis` | Cache and queues use the `redis` container |
| `BOOTSTRAP_ADMIN_NAME`/`BOOTSTRAP_ADMIN_EMAIL`/`BOOTSTRAP_ADMIN_PASSWORD` | `admin` / `admin@example.com` / `a-changeme-now` | First-admin bootstrap identity, consumed by the entrypoint |
| `SKIP_APP_BOOTSTRAP` | unset | When `1`, skips the entrypoint's asset resync/migrate/seed/bootstrap block entirely — used by `invoke-check.ps1`/`invoke-fix.ps1` for one-off `docker compose run` invocations that shouldn't race the long-lived `app` container over shared volumes |

For a direct internet connection, leave the proxy/CA variables unset or empty — every container
then uses its own default OS/JRE CA store.

## Choosing the Database Engine

The stack runs on MySQL 8 by default — no configuration needed. To run it on PostgreSQL 16
instead, uncomment this line in the root `.env` (see `.env.example`):

```
COMPOSE_FILE=docker-compose.yml;docker-compose.pgsql.yml
```

Every `docker compose` invocation — including the PowerShell scripts — then layers the
`docker-compose.pgsql.yml` override on top of the base file: a `postgres` service starts instead
of `mysql`, and the app containers are repointed at it through `DB_*` environment variables
(which take precedence over the persisted `app-laravel/.env`). The same `DB_DATABASE` /
`DB_USERNAME` / `DB_PASSWORD` values from the root `.env` are reused; `DB_ROOT_PASSWORD` is
MySQL-only.

The `;` path separator is Docker Compose's default on Windows; on Linux/macOS use `:` or set
`COMPOSE_PATH_SEPARATOR` accordingly.

Data is **not** migrated between engines: switching starts from an empty PostgreSQL database
(first boot runs migrations, seeding, and admin bootstrap exactly like a fresh install). The
`mysql_data` volume is left untouched, so removing the `COMPOSE_FILE` line brings the previous
MySQL state back.

## Outbound Mail

The app sends mail for password-reset links (self-service "Forgot password" and the admin "Send
password reset" action). The shipped default is `MAIL_MAILER=log`: nothing is delivered, and each
mail — reset link included — is written to the Laravel log instead. Read it with
`docker compose logs app` or from `storage/logs/` inside the container. This is fine for local
Docker Desktop use.

For real delivery, set these in `app-laravel/.env` and restart the app container:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
# MAIL_SCHEME=smtps   # implicit TLS (usually port 465); leave unset for STARTTLS on 587
MAIL_FROM_ADDRESS="appsec-scout@example.com"
MAIL_FROM_NAME="AppSecScout"
```

Any SMTP relay works — a corporate relay, or a cloud service such as Azure Communication
Services' SMTP endpoint for an Azure deployment.

## Running Behind a Reverse Proxy

When the app sits behind a TLS-terminating reverse proxy or cloud ingress (which forwards
requests over plain HTTP with `X-Forwarded-*` headers), set these together in the app
environment:

| Variable | Value behind a proxy | Description |
| --- | --- | --- |
| `TRUSTED_PROXIES` | proxy IPs/CIDRs, comma-separated, or `*` | Which upstream addresses may set `X-Forwarded-*` headers. Unset (the default), those headers are ignored |
| `APP_FORCE_HTTPS` | `true` | Forces the `https` scheme on every generated URL (redirects, assets, signed URLs) |
| `SESSION_SECURE_COOKIE` | `true` | Marks session cookies `Secure` so browsers only send them over HTTPS |
| `APP_URL` | the public `https://` base URL | Used for URLs generated outside a request (queue jobs, CLI) |

None of this is needed for the plain local Docker Desktop setup, where the app is reached
directly on `http://localhost:8080`.

## Cloud / Immutable Boot

The entrypoint has two boot modes:

- **Persistent local boot** (default, `APP_IMMUTABLE_BOOT` unset): the flow described above —
  persisted `.env`, first-boot `APP_KEY` generation, runtime `composer install`, asset resync,
  migrations, seeding, admin bootstrap.
- **Immutable cloud boot** (`APP_IMMUTABLE_BOOT=1` in the container environment): the image
  content is authoritative and configuration comes exclusively from the container environment.
  The entrypoint skips the persisted-`.env` handling, `composer install`, the asset resync,
  migrations, seeding, the admin bootstrap, and `chown`, then starts the container command
  directly. Intended for immutable, possibly multi-replica deployments (e.g. Azure Container
  Apps).

Immutable boot **requires `APP_KEY` in the environment** and exits with an error without it:
nothing may generate a key in this mode, and every credential-vault secret and every user's TOTP
secret is encrypted with this key — treat it as the crown jewel, store it in a secret store, and
rotate via `APP_PREVIOUS_KEYS` (comma-separated old keys, already supported by stock config).

Migrations in immutable mode are expected to run as a dedicated one-shot job executing
`php artisan migrate --force` (the same image and entrypoint, with the migrate command as the
container command). Alternatively, a deployment that guarantees a single replica can set
`APP_BOOT_MIGRATE=1` to run migrations on boot before the application starts.

## Prebuilt Container Images

Every push to `main` builds the three deployable images in CI
(`.github/workflows/image-publish.yml`), scans each with Trivy, and publishes them to the
GitHub Container Registry. Publishing is gated on the scan: an image with a fixable
HIGH or CRITICAL vulnerability is never pushed. Scan results (including unfixed CVEs,
which are reported but do not block) appear under the repository's Security > Code
scanning tab.

| Image | Contents |
| --- | --- |
| `ghcr.io/metanull/appsec-scout-2/app` | Laravel app (nginx + php-fpm + scheduler + queue worker) |
| `ghcr.io/metanull/appsec-scout-2/collector` | Repository-collection queue worker (git + Trivy client) |
| `ghcr.io/metanull/appsec-scout-2/static-analysis-collector` | Static-analysis queue worker (.NET/Java toolchain) |

Tags: `latest` (current `main`), `main`, and an immutable `sha-<short-commit>` per build —
deployments should pin the `sha-` tag.

### Running the stack from the prebuilt images

To run the whole stack from these images instead of building locally, uncomment this line
in the root `.env` (see `.env.example`; the same `COMPOSE_FILE` mechanism as the database
engine switch — combining both means listing all three files):

```
COMPOSE_FILE=docker-compose.yml;docker-compose.ghcr.yml
```

then start the stack as usual — `.\scripts\appsec-scout.ps1` (which detects the override,
skips the build, and pulls instead) or plain `docker compose up -d`. Optionally pin the
image tag with `APPSEC_IMAGE_TAG=sha-<short-commit>` in the same `.env` (default:
`latest`).

What changes relative to the source track (full details in the header comment of
`docker-compose.ghcr.yml`):

- Nothing is built: `app`, `collector`, and `static-analysis-collector` (and the two
  helper services that reuse the app image) run the published GHCR images.
- The image content is authoritative: the `./app-laravel` bind mount and the vendor/cache
  volumes are dropped, so the running code is exactly what CI built and scanned.
- Data and state volumes are unchanged — switching a stack between built and pulled
  images preserves the database, credentials, and `APP_KEY`.
- The `ops` and `claude` profile images are workstation-side dev tools, never published,
  and not part of this mode.

### Immutable cloud deployments

For the future Azure deployment, `.github/workflows/acr-promote.yml` (manual trigger)
imports a chosen `sha-` tag from GHCR into Azure Container Registry server-side, so ACR
receives the exact digests that passed the Trivy gate — images are never rebuilt for ACR.
It is prepared but untested until Azure access exists; its header comment lists the Azure
prerequisites (ACR, OIDC federated credential, repository variables).

Where egress is TLS-inspected but mounting certificates at runtime (see
[Corporate Proxy and SSL Inspection](#corporate-proxy-and-ssl-inspection)) is impractical —
or trust is required to live inside the scanned, pinned artifact — extend the published
image with a derived Dockerfile instead:

```dockerfile
FROM ghcr.io/metanull/appsec-scout-2/app:sha-<short-commit>
COPY certs/ /usr/local/share/ca-certificates/
RUN update-ca-certificates
```

This is a recipe, not repo tooling: a seconds-long build with no source checkout, producing
a deployment-owned image whose digest embeds the corporate trust chain.

## Corporate Proxy and SSL Inspection

Corporate proxy support has two independent halves. The **runtime half applies to both
install tracks** — it is all a prebuilt-image install needs, since pulled images are never
rebuilt. The **build-time half applies only to the build-from-source track**, because the
image build itself (Composer, npm, apt) must also reach the internet through the proxy.

### Runtime settings (both install tracks)

The proxy variables are plain runtime configuration: set them in the root `.env` and every
container in the stack receives them through its environment — no image rebuild involved.

```
HTTP_PROXY=http://proxy.corp.example.com:3128
HTTPS_PROXY=http://proxy.corp.example.com:3128
NO_PROXY=localhost,127.0.0.1,mysql,redis
```

When the proxy TLS-inspects outbound HTTPS, the corporate CA chain is also a runtime
concern: every `.crt` file in `.docker/certs/` is installed into the `app`, `collector`,
and `static-analysis-collector` containers' trust stores at each container start (the
`/host-certs` mount — see `docker/entrypoint.sh`; the static-analysis JDK's own truststore
is regenerated in the same step). `trivy-server` and Dependency-Track consume the same
directory at start as well. Populate `.docker/certs/` from the host's trusted CA store:

- `.\scripts\appsec-scout.ps1` does it automatically — on every run in prebuilt-image
  mode, and on `-Rebuild` in build mode (via `Export-HostCertificates` in
  `scripts/lib/Certificates.psm1`; there is no separate script to run by hand);
- without PowerShell, run `Export-HostCertificates` manually
  (`Import-Module scripts/lib/Certificates.psm1; Export-HostCertificates -OutputDir .docker/certs`)
  or drop PEM-encoded `.crt` files into `.docker/certs/` yourself.

Only set `SSL_CERT_FILE` when a custom CA bundle needs to be pointed to explicitly inside
the container; if unset or empty, outbound HTTPS uses the default CA store.

### Build-time settings (build-from-source track only)

The image build reads the same values: the proxy variables are passed as build arguments,
and the build copies the exported `.crt` files into every stage so Composer, npm, and apt
trust the proxy during the build. A clean rebuild re-exports the certificates first:

```powershell
$env:HTTP_PROXY = 'http://proxy.corp.example.com:3128'
$env:HTTPS_PROXY = 'http://proxy.corp.example.com:3128'
$env:NO_PROXY = 'localhost,127.0.0.1,mysql,redis'
.\scripts\appsec-scout.ps1 -Rebuild
```

Prebuilt-image installs never need this section; for immutable cloud deployments where the
runtime mount is impractical, bake the chain into a derived image instead — see
[Immutable cloud deployments](#immutable-cloud-deployments).

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

## Entra ID Sign-In (Optional)

Local Docker Desktop installs need none of this — with `ENTRA_ENABLED` unset, authentication is
local password + TOTP only and nothing below applies.

To offer "Sign in with Microsoft" (e.g. for an Azure deployment):

1. Create an Entra app registration on the **Web** platform with redirect URI
   `https://<app-host>/auth/entra/callback`, and a client secret (store it in a secret store —
   Key Vault in Azure).
2. Define **App Roles** named exactly `Reader`, `Triage`, `Plan`, `Sync`, `Admin` on the
   registration, and assign Entra groups (or users) to them on the Enterprise Application. The
   role *names* arrive in the token and map 1:1 onto the app's Spatie roles at every login; a
   user with no assigned App Role signs in with no roles.
3. Set the environment:

   ```dotenv
   ENTRA_ENABLED=true
   ENTRA_TENANT_ID=<directory-tenant-id>
   ENTRA_CLIENT_ID=<application-client-id>
   ENTRA_CLIENT_SECRET=<client-secret>
   # Optional; defaults to APP_URL + /auth/entra/callback
   ENTRA_REDIRECT_URI=
   ```

Behind a TLS-terminating ingress the reverse-proxy variables (`TRUSTED_PROXIES`,
`APP_FORCE_HTTPS`, `SESSION_SECURE_COOKIE`, `APP_URL`) must also be set, or the OIDC redirect URI
is generated as `http://`. Local password + TOTP sign-in — including the bootstrap admin as
break-glass — keeps working alongside Entra. Full behavior (matching, role sync, MFA policy,
lifecycle) in [docs/security.md](security.md#entra-id-federated-sign-in-optional).

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
