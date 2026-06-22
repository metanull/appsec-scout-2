# AppSec Scout — Operations Reference

This guide covers day-2 operations for the implemented M1 to M6 Laravel app.

Related documents:

- [docs/install.md](install.md)
- [docs/admin.md](admin.md)
- [docs/security.md](security.md)
- [docs/roles/admin.md](roles/admin.md)

## Build And Start

```bash
# Export host-trusted CAs first when needed
./scripts/export-host-ca.sh
./scripts/export-host-ca.ps1

# Build the default application image
docker compose build

# Build the development image with dev dependencies
APP_BUILD_TARGET=dev docker compose build app

# Start the full stack
docker compose up --build -d
```

Start and stop commands:

```bash
docker compose up -d
docker compose stop
docker compose down
docker compose down -v
```

## Health And Access

| URL | Purpose |
| --- | --- |
| `http://localhost:8080/` | Filament application shell and login |
| `http://localhost:8080/up` | Health endpoint |

Quick checks:

```bash
docker compose ps
curl http://localhost:8080/up
docker compose logs -f app
```

## Database, Migrations, And Seeding

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed
```

Bootstrap the first Admin account:

```bash
docker compose exec app php artisan appsec:bootstrap-admin \
    --name="Admin" \
    --email="admin@example.com" \
    --password="changeme-now"
```

The command refuses to run after the first user exists.

## Queue And Scheduler Model

The single `app` container runs all application processes through Supervisor:

- `nginx`
- `php-fpm`
- `php artisan schedule:work`
- `php artisan queue:work --tries=3 --max-time=3600`

Runtime backends in Compose:

- queue: Redis
- cache: Redis
- sessions: Redis

The scheduler uses one minutely dispatcher entry, `integrations:dispatch-due`, to decide which source fetch or tracker refresh jobs are due from database-backed integration settings.

Manual checks:

```bash
docker compose exec app supervisorctl status
docker compose exec app php artisan schedule:run
docker compose exec app php artisan queue:work --once
```

## Operations Page

The Admin `Operations` page is the main operator surface for background activity.

It shows:

- queued job count
- failed job count
- recent failed jobs with redacted payload previews
- recent sync runs
- recent error records
- the AppSec Scout schedule entries managed in the container

It supports the following actions:

- dispatch due integrations now
- queue a selected source fetch
- queue a selected tracker refresh
- queue a work item reconciliation (find and create missing alert-to-work-item links)
- prune audit logs
- prune error logs
- retry one failed job
- forget one failed job

Every action emits an audit row.

## Failed Jobs

Failed jobs are stored in Laravel's `failed_jobs` table using UUID identifiers.

From the Operations page, Admin users can:

- retry a failed job, which requeues the stored payload and removes the failed row
- forget a failed job, which deletes the failed row without retrying it

If a failed job needs deeper inspection, review the payload preview in the page and then check application logs for the full exception context.

## Logs And Error Records

Preferred operational log views:

```bash
docker compose logs -f app
docker compose logs -f mysql
docker compose logs -f redis
```

Application errors are also copied into the `error_logs` table and exposed in the Admin `Errors` resource.

Audit records are written to `audit_logs` and exposed in the Admin `Audit Log` resource.

## Credentials And Integrations

Admin operators manage integration configuration from three places:

- `Admin -> System credentials` for shared system-owned credentials
- `Profile integrations` for the signed-in user's personal credentials
- `Admin -> Integrations` for enablement, interval, service user selection, connection tests, and the Jira default project key

Background resolution order now depends on the integration setting:

1. explicit preferred user if a flow provides one
2. authenticated user's personal credential for interactive actions
3. integration-specific service user credential when configured
4. system credential

## Tracker Project Scope

Each Software System and each Security Container can have one or more **Tracker project links** — a mapping of `(tracker_id, project_key)` that controls which project work items are created in and which projects are searched during reconciliation.

Links are managed in two ways:

- **Manually**, via the `Tracker project links` tab on the Software System or Security Container detail page.
- **Automatically learned**, whenever a work item is created or linked from a specific alert — the tracker and project key are recorded against every system and container that the alert belongs to.

A global **Jira default project key** can also be set on the `Admin → Integrations` page under the Jira tracker row. This value is used as a fallback when no project link exists for the event's system or container.

## Work Item Reconciliation

Reconciliation finds missing `alert ↔ work item` links without duplicating existing ones. It runs in the background and operates on the following heuristics:

1. **URL cross-reference**: if an alert's `url` or `version_control_url` matches the `work_item_url` stored on a `WorkItemLink` for another event, the current event is linked to that work item.
2. **Description scan**: if a work item's description (fetched live from the tracker) contains the alert's URL, the alert is linked to that work item.

Reconciliation can be triggered from two places:

- **Operations page** → `Reconcile work items` action — queues a `ReconcileAllJob` that processes every alert.
- **Alert detail page** → `Reconcile work items` action — queues a `ReconcileEventJob` scoped to that alert only.

Both actions require the `work-items.link` permission. Every new link created by reconciliation produces an audit row with `via: reconciliation`.

## Development Verification

Run all checks from the repository root after rebuilding the dev image:

```bash
#APP_BUILD_TARGET=dev docker compose build app --no-cache
APP_BUILD_TARGET=dev docker compose build app
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/pint --test
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/pest --no-coverage
APP_BUILD_TARGET=dev docker compose run --rm app composer smoke
```

PowerShell equivalent:

```powershell
$env:APP_BUILD_TARGET = 'dev'
#docker compose build app --no-cache
docker compose build app
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose run --rm app vendor/bin/pest --no-coverage
docker compose run --rm app composer smoke
Remove-Item Env:\APP_BUILD_TARGET
```

Helper scripts for the same dev-container checks:

```powershell
# Run all checks
.\scripts\invoke-check.ps1

# Run selected check passing the `-Check` parameter to specify which checks to run:
#  - `-Check all` (runs all checks read-only/without fixing)
#  - `-Check lint` (runs code style checks)
#  - `-Check lint-fix` (runs code style checks with auto-fixing)
#  - `-Check test` (runs all tests)
#  - `-Check test-sqlite` (runs tests with SQLite in-memory database)
#  - `-Check test-mysql` (runs tests with MySQL test database)
#  - `-Check static-analysis` (runs static analysis checks)
#  - `-Check smoke` (runs smoke tests, for example, checking if the app can serve a page successfully)
#  - `-Check dependencies` (runs composer check for outdated dependencies)
#  - `-Check dependencies-fix` (runs composer update to fix outdated dependencies)
.\scripts\invoke-check.ps1 -Check 'static-analysis'
```

CI intentionally stops at the existing Laravel quality gates. It does not build a production image, generate an SBOM, or run image scans.
