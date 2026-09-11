# AppSec Scout — Quickstart (Prebuilt Images)

This is the operator path: run AppSec Scout from CI-published images without cloning the
repository. You only need the small file set listed below.

If you have (or want) a full clone of the repository — for example to build the images
yourself, or to use the `scripts/appsec-scout.ps1` convenience launcher — use
[docs/install.md](install.md) instead, which covers the build-from-source Developer flow.

## Files you need

| File | Purpose |
| --- | --- |
| `docker-compose.yml` | Base stack definition (services, volumes, healthchecks) |
| `docker-compose.ghcr.yml` | Switches every AppSec Scout service to the published images instead of building |
| `docker-compose.pgsql.yml` | Optional: switches the database engine from MySQL to PostgreSQL 16 |
| `.env.example` | Copy to `.env` and fill in |

The easiest way to get exactly this set: every push to `main` publishes a matching
"Deployment bundle" GitHub Release (tag `sha-<short-sha>`, the same short SHA as the
`sha-<short-sha>` image tag it pairs with) with these four files attached as a zip —
see the repository's Releases page. You can also copy the four files by hand from the
repository if you prefer a different ref.

Nothing else is required: no `app-laravel/`, no `scripts/`, no Dockerfiles.

## Home

1. Unzip the deployment bundle (or gather the four files above) into an empty directory.
2. Copy the env file:

   ```bash
   cp .env.example .env
   ```

3. In `.env`, uncomment the GHCR switch so every `docker compose` invocation pulls
   instead of building:

   ```
   COMPOSE_FILE=docker-compose.yml;docker-compose.ghcr.yml
   ```

   (The `;` separator is Docker Compose's default on Windows; on Linux/macOS use `:`,
   or set `COMPOSE_PATH_SEPARATOR` to match.) Optionally pin an immutable build instead
   of tracking `latest`:

   ```
   APPSEC_IMAGE_TAG=sha-<short-commit>
   ```

4. Pull and start:

   ```bash
   docker compose pull
   docker compose up -d
   docker compose wait dependencytrack-bootstrap
   curl http://localhost:8080/up
   ```

5. Open `http://localhost:8080/`, sign in with the bootstrap admin
   (`admin@example.com` / `a-changeme-now` unless you changed
   `BOOTSTRAP_ADMIN_EMAIL`/`BOOTSTRAP_ADMIN_PASSWORD`), and complete TOTP enrollment.

No corporate CA certs, no ACR, no Entra ID — none of that applies to a Home install.
Database stays on the default MySQL 8 container; `docker-compose.pgsql.yml` isn't
needed unless you specifically want PostgreSQL.

## Corporate (User or VM Hosting)

Everything Home needs above, plus the items below. "User" and "VM Hosting" are the same
file set and the same steps — the only difference is whether the four files live on your
own workstation or on the VM.

### a. Mirror the six images into your ACR

CI publishes six images to GHCR (`app`, `collector`, `static-analysis-collector`,
`mysql`, `postgres`, `trivy-server` — see `.github/workflows/image-publish.yml`). Pulling
directly from GHCR from a Corporate network is often blocked; instead mirror them into
your own Azure Container Registry with `az acr import`. This is a manual, one-time (per
tag) step — `.github/workflows/acr-promote.yml` documents the same import but CI has no
network path into a Corporate Azure tenant, so it is never run automatically.

```bash
az login
TAG=sha-<short-sha>          # pick the tag from the Release/image you want to promote
ACR_NAME=<your-acr-short-name>
SOURCE=ghcr.io/metanull/appsec-scout-2

for name in app collector static-analysis-collector mysql postgres trivy-server; do
  az acr import \
    --name "$ACR_NAME" \
    --source "${SOURCE}/${name}:${TAG}" \
    --image "appsec-scout-2/${name}:${TAG}" \
    --image "appsec-scout-2/${name}:latest" \
    --force
done
```

This matches the flags `.github/workflows/acr-promote.yml` uses for its own three
images, extended to all six. The GHCR packages are public, so no source-registry
credentials are needed; if they are ever made private, add
`--username <github-username> --password <PAT with read:packages>` to each import.

`redis`, and the three `dependencytrack-*` images, are **not** part of this list —
they are pulled straight from Docker Hub in every mode (Home and Corporate alike) and
are not AppSec Scout's own images, so there is nothing to mirror for them.

### b. Point the stack at your ACR

In `.env`:

```
COMPOSE_FILE=docker-compose.yml;docker-compose.ghcr.yml
APPSEC_IMAGE_REGISTRY=<your-acr-short-name>.azurecr.io/appsec-scout-2
APPSEC_IMAGE_TAG=sha-<short-sha>
```

`docker compose pull && docker compose up -d` now pulls every AppSec Scout image from
your ACR instead of GHCR.

### c. Trust your corporate CA

```bash
mkdir -p .docker/certs
# copy your corporate/TLS-inspecting proxy CA chain in, as PEM-encoded .crt files
cp /path/to/your-corporate-ca.crt .docker/certs/
```

Every container that talks outbound (`app`, `collector`, `static-analysis-collector`,
`trivy-server`, plus Dependency-Track's own truststore) installs everything under
`.docker/certs/` at each container start — no rebuild needed, since these are prebuilt
images. Also set the proxy variables in `.env` if outbound traffic goes through a
forward proxy:

```
HTTP_PROXY=http://proxy.corp.example.com:3128
HTTPS_PROXY=http://proxy.corp.example.com:3128
NO_PROXY=localhost,127.0.0.1,mysql,redis
```

### d. Entra ID SSO (optional)

Local password + mandatory TOTP sign-in always works, with or without Entra ID —
enabling SSO below doesn't change or replace it. The `ENTRA_*` variables are read by
the Laravel app from its own `app-laravel/.env`, not the root `.env` used so far. In a
full clone that file is on disk; here, without one, it is the file the entrypoint
persisted into the `app_storage` volume on first boot. Edit it directly, in place, after
the stack has started once:

```bash
docker compose cp app:/var/www/html/storage/app/private/.env ./laravel.env
# edit laravel.env: add/update ENTRA_ENABLED=true, ENTRA_TENANT_ID=, ENTRA_CLIENT_ID=,
# ENTRA_CLIENT_SECRET=, and optionally ENTRA_REDIRECT_URI=
docker compose cp ./laravel.env app:/var/www/html/storage/app/private/.env
docker compose restart app
rm ./laravel.env
```

See [docs/security.md](security.md#entra-id-federated-sign-in-optional) for the Entra
app registration steps (redirect URI, App Roles, client secret) this pairs with. Behind
a TLS-terminating ingress, set `TRUSTED_PROXIES`, `APP_FORCE_HTTPS`,
`SESSION_SECURE_COOKIE`, and `APP_URL` the same way (same file) or the OIDC redirect URI
is generated as `http://`.

### e. Database: bundled container or external/central Postgres

By default this stack still runs MySQL 8, same as Home. To run PostgreSQL 16 instead,
add `docker-compose.pgsql.yml` to the `COMPOSE_FILE` chain:

```
COMPOSE_FILE=docker-compose.yml;docker-compose.pgsql.yml;docker-compose.ghcr.yml
```

Then choose one:

- **Bundled Postgres container** (same as Home, just on Postgres): also set
  `COMPOSE_PROFILES=local-db` in `.env` so the `postgres` service starts.
- **External/central Postgres** (a Corporate-managed server): leave
  `COMPOSE_PROFILES` unset (or without `local-db`) and instead set, in `.env`:

  ```
  DB_HOST=<external-postgres-host>
  DB_PORT=5432
  DB_USERNAME=<...>
  DB_PASSWORD=<...>
  ```

  For TLS against a corporate CA, also set `DB_SSLMODE=require` and `DB_SSLROOTCERT`
  (path to the CA file inside the container — mount it the same way as
  `.docker/certs`), or `DB_SSLCERT`/`DB_SSLKEY` for client-certificate auth. For Azure
  Managed Identity auth instead of a password, set `DB_AZURE_MANAGED_IDENTITY=true`
  (and `DB_AZURE_MANAGED_IDENTITY_CLIENT_ID` for a user-assigned identity) — the
  Postgres role matching the identity must already exist on the server.

  In this mode no `postgres` container ever starts — `docker-compose.pgsql.yml` only
  supplies the `DB_*` overrides for `app`/`collector`/`static-analysis-collector`.

## What you don't need here

`scripts/appsec-scout.ps1` is a convenience wrapper for a full git clone (it also
drives image *building*, which doesn't apply here). Everything above is plain
`docker compose` — no PowerShell, no repository checkout required. If you later decide
you want the full repository (development, or just to use the helper script), see
[docs/install.md](install.md).
