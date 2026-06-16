# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture

- PHP 8.4 with Laravel 13 and Filament 5 (single panel, root path, amber theme); MySQL 8 and Redis 7
- UI is Filament-native — it is the main and only UI, not reserved to admins
- Spatie permissions for RBAC; Laravel Fortify for auth with mandatory app-based TOTP 2FA
- Sources (AzDo, Asoc, Detectify) and Trackers (GitHub, Jira) follow a tagged-singleton registry pattern, bound at boot in AppServiceProvider
- Local DB is the system of record; upstream sources are read-only except when Sync explicitly pushes state
- All write actions record an AuditLog entry (actor, action, old/new values)

## Environment

Docker is the only environment for development and usage. `docker-compose.yml` defines three services:

| Service | Image | Role |
|---------|-------|------|
| `app` | `appsec-scout:latest` | Laravel app (nginx + php-fpm + scheduler + queue worker via Supervisor) |
| `mysql` | `8.0` | Primary database; creates `appsec_scout_test` DB on init |
| `redis` | `7-alpine` | Cache and queue backend |

Users interact with the environment through three PowerShell scripts:

```powershell
# Start all containers (add -Rebuild to rebuild images, -Force to skip prompt)
.\scripts\appsec-scout.ps1 [-Rebuild] [-Force]

# Run CI checks inside the container
.\scripts\invoke-check.ps1 [-Check {all|lint|test|test-sqlite|test-mysql|static-analysis|smoke|dependencies}]

# Run automated fixes inside the container
.\scripts\invoke-fix.ps1 [-Fix {all|lint-fix|dependencies-fix}]
```

Default for `-Check` and `-Fix` is `all`. Use direct `docker compose` commands only when the scripts don't cover the need.

## Common Commands

```powershell
# Run all CI checks (lint + tests + static analysis + dependencies)
.\scripts\invoke-check.ps1

# Lint only (check formatting with Pint)
.\scripts\invoke-check.ps1 -Check lint

# Auto-fix formatting
.\scripts\invoke-fix.ps1 -Fix lint-fix

# Run tests on SQLite (fast, default)
.\scripts\invoke-check.ps1 -Check test

# Run tests on MySQL (closer to production)
.\scripts\invoke-check.ps1 -Check test-mysql

# PHPStan static analysis
.\scripts\invoke-check.ps1 -Check static-analysis

# Run a single test file
docker compose run --rm app vendor/bin/pest tests/Feature/path/to/TestFile.php

# Run a single test by name filter
docker compose run --rm app vendor/bin/pest --filter "test name"

# Run an Artisan command
docker compose exec app php artisan <command>
```

## Structure

- `/app-laravel` — Laravel application root
- `/docker` — Container build files and Supervisor config
- `/scripts` — PowerShell launcher and CI scripts
- `/docs` — System documentation (architecture, install, operations, security, admin, roles)
- `/legacy-code`, `/plan`, `/tools` — Out of scope

### Key app-laravel directories

```
app/
  Filament/          # UI layer: Pages/, Resources/, Widgets/, Support/
  Models/            # Eloquent models with Enums/, Casts/, Factories/
  Sources/           # Source integrations (AzDo, Asoc, Detectify) — Contracts + DTO factories
  Trackers/          # Tracker integrations (GitHub, Jira) — Contracts, Reconciliation, VOs
  Integrations/      # Integration scheduling and dispatch
  Sync/              # Synchronization logic
  Triage/            # Triage services (CodesearchService, TrivyService, BfgService)
  Credentials/       # Credential vault and cipher implementations
  Audit/             # AuditLog recorder
  SecurityEvents/    # Event linking and triage context
  Context/           # Application domain context
  Providers/         # AppServiceProvider (registers Source/Tracker singletons), FortifyServiceProvider, PanelProvider
routes/
  console.php        # Artisan commands + scheduler (integrations:dispatch-due runs every minute)
  web.php            # Authenticated routes for alert attachment downloads
tests/
  Feature/           # Admin, Attachments, Audit, Auth, Credentials, Filament, Integrations, Smoke, Sources, Sync, Trackers, Triage
  Unit/
```

## Filament Panel

Panel id: `appsec-scout`, path: `/` (root). Auto-discovers resources, pages, and widgets from the standard directories.

**Resources** (11): SecurityEvent, SecurityContainer, SecurityContainerLink, SoftwareSystem, SoftwareSystemLink, User, AuditLog, ErrorLog, InferenceSuggestion, RepositoryProvider, and shared RelationManagers for CuratedLinks, RepositoryMappings, TrackerProjectLinks.

**Custom Pages**: IntegrationSettingsPage, OperationsPage, PendingSyncPage, ProfileIntegrationsPage, SystemCredentialsPage.

**Widgets**: SecurityOverviewStats, SeverityDistributionChart, OpenAlertsBySource, RecentSyncRuns, RecentErrors, OperationsHealthStats, ReconciliationSummary.

## Key Models and Enums

- `SecurityEvent` — central entity; `EventState` (Open, Acknowledged, InProgress, Resolved, Dismissed), `EventSeverity` (Critical → Informational), `EventType` (Vulnerability, Secret, Dependency, License, Misconfiguration, CodeQuality, IaC, Posture)
- `SecurityContainer`, `SecurityContainerLink`, `SoftwareSystem`, `SoftwareSystemLink` — hierarchy that alerts are scoped to
- `Credential` — credential vault with explicit resolution hierarchy: preferred user → current user → integration service user → system credential

## Testing

- **Framework**: Pest 4.7+ with Laravel plugin
- **Default config** (`phpunit.xml`): SQLite in-memory — fast, used for most development
- **MySQL config** (`phpunit.mysql.xml`): requires the `mysql` container; use `-Check test-mysql` or pass `--configuration phpunit.mysql.xml` directly
- Smoke tests live in `tests/Feature/Smoke/` and are a separate `-Check smoke` target
