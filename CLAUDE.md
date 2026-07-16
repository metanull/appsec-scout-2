# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture

- PHP 8.4 with Laravel 13 and Filament 5 (single panel, root path, amber theme); MySQL 8 and Redis 7
- UI is Filament-native — it is the main and only UI, not reserved to admins
- Spatie permissions for RBAC; Laravel Fortify for auth with mandatory app-based TOTP 2FA
- Sources (AzDo, Asoc, Detectify), Trackers (GitHub, Jira), and Source Control providers (AzDO Repos, GitHub Repos) each follow the same tagged-singleton registry pattern, bound at boot in AppServiceProvider. These are three distinct concepts with their own credentials, even when the same upstream product plays more than one role (e.g. AzDO is both a Source and a Source Control provider; GitHub is both a Tracker and a Source Control provider)
- Local DB is the system of record; upstream sources are read-only except when Sync explicitly pushes state
- All write actions record an AuditLog entry (actor, action, old/new values)

## Sources and Trackers

### Supported sources

| Source | Alert types | Writeback |
|--------|-------------|-----------|
| AzDO Advanced Security | Code, Dependency, Secret | State + Comments |
| AppScan on Cloud (ASoC) | Vulnerability, Code Quality | State + Comments |
| Detectify | Misconfiguration, Vulnerability | State |
| Defender for Cloud > DevOps | Code, Dependency, Secret, IaC, Posture | **Deferred — not yet implemented** |

### Supported trackers

| Tracker | Features |
|---------|----------|
| Jira Cloud | Create + update issues (single and grouped), labels, priority, assignee, parent, ADF description |
| GitHub Issues | Create + update issues (single and grouped), labels, milestone, assignee, Markdown description |

### Supported source control providers

Source Control credentials grant repo/code access (clone, code search) and are always distinct
from the Source/Tracker credential for the same product, since the required PAT scope differs
(e.g. AzDO's "Code (Read)" for repo access vs the Advanced Security scope its Source uses).

| Source control | Credential key(s) | Used by |
|-----------------|--------------------|---------|
| Azure DevOps Repos | `azdo-repos.pat`, `azdo-repos.organization` | `triage:codesearch` (web UI path), `invoke-ops.ps1 -SbomScan`/`-StaticAnalysis` |
| GitHub Repos | `github-repos.token` | `invoke-ops.ps1 -Shell`/`-Claude` (clone/push) |

### Triage commands (Artisan namespace `triage:*`)

- `triage:codesearch {PAT} {search} [{project|repo_url}]` — AzDO code search, attaches findings with hyperlinks. From the web UI the user's PAT is resolved automatically from the AzDO Repos system credential (`azdo-repos.pat`/`azdo-repos.organization`), distinct from the AzDO source's alert-ingestion PAT.

### Credential resolution order

1. Explicit preferred user (when a flow specifies one)
2. Authenticated user's personal credential
3. Integration service user credential (when configured per integration)
4. System credential

## Environment

Docker is the only environment for development and usage. `docker-compose.yml` starts these services by default (no profile needed):

| Service | Image | Role |
|---------|-------|------|
| `app` | `appsec-scout:latest` | Laravel app (nginx + php-fpm + scheduler + queue worker via Supervisor) |
| `mysql` | `8.0` | Primary database; creates `appsec_scout_test` DB on init |
| `redis` | `7-alpine` | Cache and queue backend |
| `dependencytrack-postgres`/`-apiserver`/`-frontend` | `postgres:16-alpine` / `dependencytrack/apiserver` / `dependencytrack/frontend` | SBOM visualization; auto-provisioned by `dependencytrack-bootstrap` (team, API key, Trivy analyzer — stored in the credential vault) |
| `trivy-token-init` / `trivy-server` | `appsec-scout:latest` / `aquasec/trivy:latest` | Self-hosted vulnerability source for Dependency-Track's Trivy analyzer; the shared token between them is generated once inside the stack, no manual setup |

`node` (`profiles: tools`), `claude` (`profiles: claude`), and `ops` (`profiles: ops`) are opt-in profiles, not started by a plain `docker compose up`.

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
  SourceControl/     # Source Control providers (AzDO Repos, GitHub Repos) — Contracts, Registry
  Integrations/      # Integration scheduling and dispatch
  Sync/              # Synchronization logic
  Triage/            # Triage services (CodesearchService, StateChanger, SeverityChanger, CommentManager)
  Credentials/       # Credential vault and cipher implementations
  Audit/             # AuditLog recorder
  SecurityEvents/    # Event linking and triage context
  Context/           # Application domain context
  Providers/         # AppServiceProvider (registers Source/Tracker/Source Control singletons), FortifyServiceProvider, PanelProvider
routes/
  console.php        # Artisan commands + scheduler (integrations:dispatch-due runs every minute)
  web.php            # Authenticated routes for alert attachment downloads
tests/
  Feature/           # Admin, Attachments, Audit, Auth, Credentials, Filament, Integrations, Smoke, Sources, Sync, Trackers, Triage
  Unit/
```

## Filament Panel

Panel id: `appsec-scout`, path: `/` (root). Auto-discovers resources, pages, and widgets from the standard directories.

**Resources** (10): SecurityEvent, SecurityContainer, SoftwareSystem, SoftwareAsset, SoftwareComponent, LocalFinding, User, AuditLog, ErrorLog, RepositoryProvider, and shared RelationManagers for CuratedLinks, RepositoryMappings, TrackerProjectLinks.

**Custom Pages**: IntegrationSettingsPage, OperationsPage, PendingSyncPage, ProfileIntegrationsPage, SystemCredentialsPage.

**Widgets**: SecurityOverviewStats, SeverityDistributionChart, OpenAlertsBySource, RecentSyncRuns, RecentErrors, OperationsHealthStats.

## Filament UI Conventions

These rules apply to all Filament resources, pages, and widgets in `app/Filament/`.

- Use Filament primitives exclusively. Never add raw Blade, inline CSS, custom JavaScript, or bespoke Livewire components unless a primitive provably cannot satisfy the requirement — explain why before proceeding.
- `form()` receives and returns `Filament\Schemas\Schema`; `infolist()` receives and returns `Filament\Infolists\Infolist`. Import every Filament class explicitly.
- Use `Section::make()` to group logically related fields with a human-readable heading.
- Use `Grid::make(columns)` for two- or four-column responsive layouts on dense forms.
- Use `Tabs` when a form or infolist has more than five distinct logical groups.
- Apply `badge()->color()` on every `TextColumn` and `TextEntry` that displays an enum or status value.
- Use `placeholder()` on columns and entries to show a dash instead of blank cells.
- Use `requiresConfirmation()` on every destructive action.
- Use `ActionGroup::make([...])` when a table row has more than three actions.
- Use `since()` on datetime columns showing recency (e.g. `updated_at`, `last_login_at`).
- Use `wrap()->grow()` on long-text columns (`title`, `description`).
- Authorization: delegate `canViewAny()`, `canCreate()`, `canEdit()`, `canDelete()`, `canView()` to policies or Spatie Permission checks. Never hard-code role names.
- `canView()` on widgets must always check a permission.
- `User::canAccessPanel()` decides only whole-account access (disabled/suspended); it must not encode role-based feature authorization.
- Prefer relation managers over custom Blade for related data. Prefer `infolist()` on `ViewRecord` pages over custom view pages.
- Notifications: always use `Filament\Notifications\Notification`. Never use `session()->flash()` or custom toast JS.

### List page baseline checklist

`SecurityEventResource` (Alerts) is the canonical example — compare a new or edited list page (`table()` method) against it. A list page should have:

- The primary text column (e.g. `title`, `name`) marked `->searchable()`.
- Every orderable column marked `->sortable()` — use a `sortable(query: ...)` closure for relation or derived columns (see `App\Filament\Support\LocalFindingOwnerColumns` / `SoftwareComponentOwnerColumns` for the subquery pattern).
- Every enum/status column badge-colored with `badge()->color()` — reuse `App\Filament\Support\EventStateBadgeColor` / `EventSeverityBadgeColor` when the column is an `EventState`/`EventSeverity` value instead of re-deriving the color mapping.
- Every nullable column with `->placeholder('-')`.
- Columns beyond the first ~6 core ones marked `->toggleable()` (hidden by default where appropriate) so the table doesn't overflow horizontally.
- `->recordUrl()` pointing at a dedicated view page, unless the page is action-only by explicit, documented design.
- `->actions([...])` wrapped in `ActionGroup::make([...])` once a row has more than three actions.
- `->paginated([25, 50, 100])` for consistent page-size options.
- At least one meaningful `->filters([...])` entry when the model has an obviously filterable dimension (status, kind, boolean flag, relation) — reuse `App\Filament\Support\DateRangeFilters::for($column)` for a from/until date-range filter instead of hand-rolling one.

## Key Models and Enums

- `SecurityEvent` — central entity; `EventState` (Open, Acknowledged, InProgress, Resolved, Dismissed), `EventSeverity` (Critical → Informational), `EventType` (Vulnerability, Secret, Dependency, License, Misconfiguration, CodeQuality, IaC, Posture)
- `SoftwareAsset`, `SoftwareSystem`, `SecurityContainer` — hierarchy that alerts, local findings, and dependencies are scoped to
- `Credential` — credential vault with explicit resolution hierarchy: preferred user → current user → integration service user → system credential

## Testing

- **Framework**: Pest 4.7+ with Laravel plugin
- **Default config** (`phpunit.xml`): SQLite in-memory — fast, used for most development
- **MySQL config** (`phpunit.mysql.xml`): requires the `mysql` container; use `-Check test-mysql` or pass `--configuration phpunit.mysql.xml` directly
- Smoke tests live in `tests/Feature/Smoke/` and are a separate `-Check smoke` target

## CI vs Local Verification

**Local (operator's own workstation)**: all checks run inside Docker via the PowerShell scripts. Never run Pint, PHPStan, or Pest directly on the operator's machine — it doesn't have the pinned toolchain, only the container does.

This rule is about that workstation, not about Claude Code's own execution environment. When Claude Code itself is running in a container or cloud session (e.g. Claude Code on the web) rather than on the operator's machine:
- If Docker is available there, still prefer the PowerShell scripts / `docker compose` — same reasoning applies.
- If Docker is not available, running Pint/PHPStan/Pest directly is acceptable as a substitute — mirror what CI does (bare PHP, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) rather than improvising a different setup, and say plainly that verification ran outside Docker so it's clear CI is still the authoritative gate.

**CI (GitHub Actions)**: `.github/workflows/laravel-ci.yml` runs on a bare PHP 8.4 runner without Docker. It installs Composer dependencies, copies `.env.example`, generates an app key, then runs Pint, PHPStan, and Pest with `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`. Do not assume `.env.testing` is present in CI.

When running checks directly via `docker compose` (e.g. for a single file or narrower scope), the dev image must be active:

```powershell
$env:APP_BUILD_TARGET = 'dev'
docker compose build app           # rebuild only when Dockerfile or composer.json changed
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose run --rm app vendor/bin/pest --no-coverage
Remove-Item Env:\APP_BUILD_TARGET
```

All three gates (Pint clean, PHPStan clean, Pest green) must pass before reporting code work complete.

## Coding Rules

### Framework first

- Prefer Laravel 13, Filament 5, Fortify, Spatie Permission, Eloquent, queues, jobs, events, validation, policies, casts, notifications, and config APIs before custom code.
- Do not introduce new packages without explicit user approval. Any dependency must be current, maintained, non-vulnerable, and necessary.
- Use vendor-supported extension points instead of overriding framework internals.

### Security

- Never self-implement authentication, authorization, sessions, password handling, CSRF protection, encryption, rate limiting, or 2FA flows.
- Use Fortify for auth and mandatory TOTP 2FA. Use Spatie Permission for roles and permissions.
- Gate every protected action through Laravel policies, Spatie permissions, Filament authorization hooks, middleware, or Laravel authorization APIs.
- Store PATs and secrets via the approved encrypted Laravel model casts and credential flows. Never log secrets or expose them in UI text, exceptions, tests, or seed data.
- Never pass user-supplied data to dynamic execution functions, command execution contexts, or unsafe deserialization mechanisms.
- Never execute shell strings from application code. Use `Laravel\Process`, Symfony Process with explicit argv arrays, or framework APIs.

### Reliability

- Fail fast. Do not swallow, hide, disguise, or silently downgrade errors.
- All application errors must be logged and surfaced to the user clearly.
- Do not add fallback mechanisms, placeholder code, temporary implementations, or degraded behavior without explicit user approval.
- All write actions must produce an audit record with timestamp and actor identity.

### Code quality

- Keep classes and methods single-purpose, small, and testable (low cyclomatic complexity).
- Prefer constructor injection, container bindings, typed value objects, casts, and service classes over facades hidden inside business logic.
- Use Eloquent relationships, scopes, casts, accessors, mutators, and query builders instead of raw SQL unless a concrete documented need exists.
- Use structured parsers and framework helpers for parsing or transformation. Do not use regex to parse data formats.
- Keep changes small and direct. Write or update Pest tests for every new feature and bug fix.
- Test business behavior, not Laravel, Filament, Pest, or third-party framework internals.
- Follow Laravel Pint with `pint.json`. Use explicit typed PHP signatures; add PHPDoc only where PHPStan, generics, or framework magic require it.

### Database portability

- Prefer Eloquent, query builder methods, casts, relationships, scopes, and Schema builder APIs over raw SQL.
- Do not use database-driver-specific SQL functions (`MATCH ... AGAINST`, `JSON_EXTRACT`, `JSON_UNQUOTE`, MySQL-specific casts) when a portable framework alternative exists.
- Keep search and filter behavior portable across MySQL and SQLite. Use `LIKE`-based matching rather than vendor-specific fulltext expressions.
- Use `Schema::hasIndex()`, `whenTableHasIndex()`, `whenTableDoesntHaveIndex()` for index inspection rather than raw SQL.
- Production is MySQL 8. SQLite is for tests and portability verification only — never a production fallback.
- Write reversible migrations and preserve MySQL 8 compatibility.

## Story Writing

Use these rules when writing implementation stories, GitHub issues, epics, or milestones.

### Story rules

- One story = one change. Split independent changes into separate stories.
- Stories must be self-contained: all context, constraints, and decisions required for implementation are included in the story itself.
- Stories must be unambiguous: no choices, open questions, or alternative approaches. Clarify with the user before writing if the request is ambiguous.
- Verify whether the requested behavior already exists before writing implementation instructions.

### Required story structure

- **Title** — imperative or outcome-focused; names the single change.
- **Context** — relevant project state, user-facing workflow, milestone, architectural boundary, and existing code pattern.
- **Problem** — the exact gap, defect, or missing behavior being addressed.
- **Solution** — the required end state in direct terms.
- **Implementation Instructions** — precise, ordered steps following established project patterns; names expected files, components, routes, tests, or services.
- **Definition of Done** — all acceptance criteria including: behavior implemented and follows conventions; tests added or updated; Pint clean; Pest green; PHPStan clean; no unrelated changes.

### Epics and milestones

- Create an epic when the change is too large to implement safely as one story. An epic includes context, the larger problem, high-level solution, cross-cutting constraints, and child stories.
- Create a milestone when multiple epics are required or when stories span different domains with meaningful dependencies.
- When creating epics and stories in GitHub: create the epic issue first, then create child stories with a link to the epic. Never create child stories before the epic URL is available.
- Child stories under an epic still follow the full story structure and describe exactly one change each.
