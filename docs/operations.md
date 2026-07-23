# AppSec Scout — Operations Reference

This guide covers day-2 operations for the Laravel app.

Related documents:

- [docs/install.md](install.md)
- [docs/admin.md](admin.md)
- [docs/security.md](security.md)
- [docs/roles/admin.md](roles/admin.md)
- [docs/concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md) — the
  host-triggered SBOM/static-analysis scan pipeline covered in its own section below

## Helper Scripts

All day-to-day operations go through one of these PowerShell scripts (see each script's own
`Get-Help` for full parameter documentation):

| Script | Purpose |
| --- | --- |
| `scripts/appsec-scout.ps1` | Start/rebuild the stack (`-Rebuild` for a clean slate, `-Force` to skip the build cache) |
| `scripts/invoke-app.ps1` | Shell/restart/tinker/artisan against the already-running `app` container |
| `scripts/invoke-check.ps1` | Read-only checks: lint, tests, static analysis, dependency audit |
| `scripts/invoke-fix.ps1` | Mutating fixes: auto-format, dependency updates |
| `scripts/invoke-ops.ps1` | Sandboxed `ops` container: shell, Claude Code, org-wide SBOM/static-analysis scans |

Use direct `docker compose`/`docker compose exec` commands only when these scripts don't cover
the need.

## Build And Start

```powershell
.\scripts\appsec-scout.ps1
```

Rebuilds the `app` image (cache permitting) and starts every non-profiled service — see
[docs/architecture.md](architecture.md#runtime-topology) for the full service list. Manual
equivalent, plus stop/teardown:

```bash
docker compose up --build -d
docker compose stop
docker compose down
docker compose down -v   # also removes named volumes (database, storage, etc.)
```

## Health And Access

| URL | Purpose |
| --- | --- |
| `http://localhost:8080/` | Filament application shell and login |
| `http://localhost:8080/up` | Health endpoint |
| `http://localhost:8090/` | Dependency-Track UI |
| `http://localhost:8091/` | Dependency-Track API |

Quick checks:

```bash
docker compose ps
curl http://localhost:8080/up
docker compose logs -f app
```

## Restarting, Shell Access, And Artisan

```powershell
.\scripts\invoke-app.ps1                 # shell into the running app container
.\scripts\invoke-app.ps1 -Restart        # re-run the entrypoint's boot sequence without recreating the container
.\scripts\invoke-app.ps1 -Tinker         # interactive php artisan tinker
.\scripts\invoke-app.ps1 -Artisan <cmd>  # run one artisan command and exit
```

`-Restart` re-runs migrations, seeding, the admin bootstrap (skipped once a user exists), the
static-asset resync, and permission cache reset — the same sequence the container ran on its
first boot — without recreating the container or touching any volume.

## Queue And Scheduler Model

The `app` container runs every application process through Supervisor (`docker/supervisord.conf`):

- `nginx`
- `php-fpm`
- `php artisan schedule:work`
- `php artisan queue:work --tries=3 --timeout=1800 --max-time=3600`

Queue, cache, and session backends are all Redis. Source fetch and Tracker refresh are not
scheduled: they run only when triggered on demand from `Admin -> Operations` (or the
`assets:sync-azdo-projects` CLI command) — see [docs/concepts/integration.md](concepts/integration.md)
for the full trigger model. The scheduler's minutely entries are limited to the SBOM/static-analysis
pending-scan importers; log pruning runs daily.

Manual checks:

```bash
docker compose exec app supervisorctl status
docker compose exec app php artisan schedule:run
docker compose exec app php artisan queue:work --once
```

## Operations Page

`Admin -> Operations` (gated by `admin.queue` or `work-items.sync`) is the main operator surface
for background activity. It shows:

- Queued job count, failed job count.
- Recent failed jobs with redacted payload previews.
- Recent sync runs and recent error records.
- Reconciliation and inventory-sync last-run summaries (new links created; systems/containers
  synced).
- SBOM scan status (most recent SbomScan/StaticAnalysis run per repository).
- The AppSec Scout schedule entries managed in the container.

Actions:

| Action | Gate | Effect |
| --- | --- | --- |
| Fetch source | `admin.queue` or `work-items.sync` | Dispatches `FetchSourceJob` for one chosen Source right now |
| Refresh tracker | `admin.queue` or `work-items.sync` | Dispatches `RefreshWorkItemsJob` for one chosen Tracker right now |
| Reconcile all tracker links | `admin.queue` or `work-items.sync` | Dispatches `ReconcileAllJob`, sweeping every alert for missing work-item links |
| Sync inventory | `admin.queue` | Dispatches `SyncInventoryJob`, syncing `SoftwareSystem`/`SecurityContainer` rows from every registered Source and every Source Control provider that supports it |
| Prune audit logs / Prune error logs | `admin.queue` or `work-items.sync` | Deletes retention-expired rows now |
| Retry / Forget a failed job | `admin.queue` or `work-items.sync` | Requeues the stored payload, or discards it, per row |

Every action writes an audit row.

## Failed Jobs

Failed jobs are stored in Laravel's `failed_jobs` table using UUID identifiers. From the
Operations page, Admin/Sync users can retry a failed job (requeues the stored payload and removes
the failed row) or forget it (deletes the failed row without retrying). Review the payload preview
in the page, then check application logs for the full exception context if deeper inspection is
needed.

## Logs And Error Records

```bash
docker compose logs -f app
docker compose logs -f mysql
docker compose logs -f redis
docker compose logs -f dependencytrack-apiserver
docker compose logs -f trivy-server
```

Application errors are also copied into the `error_logs` table and exposed in the Admin `Errors`
resource. Audit records are written to `audit_logs` and exposed in the Admin `Audit Log` resource.

## Credentials And Integrations

Admin operators manage integration credentials from two places:

- `Admin -> System Credentials` for shared, system-owned credentials (with a connection test).
- `Profile -> Integrations` for the signed-in user's own personal credentials (with a connection
  test).

Integrations have no enablement or interval settings and are not scheduled — they sync on demand
from `Admin -> Operations`.

There are exactly two credential-resolution flows: system-triggered operations (background jobs,
Ops-page fetch/refresh actions, `assets:sync-azdo-projects`) resolve the system credential;
user-triggered interactive actions resolve that specific user's own personal credential. Which
flow applies is fixed by the kind of operation. A missing required credential fails with a clear
error.

## Tracker Project Scope

Each Software System and each Security Container can have one or more Tracker Project Links — a
mapping of `(tracker_id, project_key)` controlling which project new work items are created in and
which projects are searched during reconciliation. Links are created manually (via the relation
manager on the System/Container's Filament page) or auto-learned whenever a work item is created or
linked from an alert. A global Jira default project key stored in tracker configuration
(`TrackerConfig`) is used as a fallback when no link exists for the alert's system/container. Full
resolution order in [docs/concepts/links-and-defaults.md](concepts/links-and-defaults.md).

## Work Item Reconciliation

Reconciliation finds missing alert-to-work-item links by matching an alert's own URL against text
mined from candidate tracker issues, without an operator manually searching. It runs from two
places:

- **Alert detail page** — "Find existing work items" calls `ReconciliationService::reconcileEvent()`
  synchronously, scoped to the alert's own Tracker Project Links when one exists (falling back to
  every configured project, with a warning, when it doesn't).
- **Operations page** — "Reconcile all tracker links" dispatches `ReconcileAllJob`, sweeping every
  alert in the background.

Both require `work-items.link` or `work-items.sync`. Every new link produces an audit row. Full
detail in [docs/concepts/triage.md](concepts/triage.md#reconciliation-the-same-linking-mechanism-two-triggers).

## SBOM and Static Analysis Scanning

Separately from the always-on Source/Tracker sync above, `scripts/invoke-ops.ps1 -SbomScan` and
`-StaticAnalysis` run host-triggered, organization-wide scans (Trivy for SBOM/vulnerabilities/
secrets; Roslynator and SpotBugs for static analysis) against every repository in an Azure DevOps
organization, feeding results into appsec-scout as Local Findings and Dependencies. This is
entirely operator-initiated — there is no scheduler entry or in-app button to start a scan. Full
detail, including the Dependency-Track visualization pipeline, in
[docs/concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md).

```powershell
.\scripts\invoke-ops.ps1 -SbomScan -Credential (Get-Credential)
.\scripts\invoke-ops.ps1 -StaticAnalysis -Credential (Get-Credential)
```

## Development Verification

```powershell
.\scripts\invoke-check.ps1                       # run all read-only checks
.\scripts\invoke-check.ps1 -Check lint            # Pint, read-only
.\scripts\invoke-check.ps1 -Check test            # Pest (SQLite)
.\scripts\invoke-check.ps1 -Check test-mysql      # Pest (MySQL)
.\scripts\invoke-check.ps1 -Check static-analysis # PHPStan
.\scripts\invoke-check.ps1 -Check smoke           # smoke tests
.\scripts\invoke-check.ps1 -Check dependencies    # composer outdated-dependency check
.\scripts\invoke-check.ps1 -Check npm-audit       # npm audit

.\scripts\invoke-fix.ps1                          # run all auto-fixes
.\scripts\invoke-fix.ps1 -Fix lint-fix            # Pint, auto-fix
.\scripts\invoke-fix.ps1 -Fix dependencies-fix    # composer update
.\scripts\invoke-fix.ps1 -Fix npm-audit-fix       # npm audit fix
.\scripts\invoke-fix.ps1 -Fix npm-update          # npm update
```

Direct `docker compose` equivalent for a single tool, run against the dev image:

```powershell
$env:APP_BUILD_TARGET = 'dev'
docker compose build app
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose run --rm app vendor/bin/pest --no-coverage
Remove-Item Env:\APP_BUILD_TARGET
```

CI (`.github/workflows/laravel-ci.yml`) runs Pint, PHPStan, and Pest on a bare PHP 8.4 runner
without Docker. It does not build a production image, run Trivy image scans, or publish an SBOM
artifact as part of that workflow — the Dependency-Track/Trivy pipeline above is a separate,
always-on part of the running application, not a CI gate.
