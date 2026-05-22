# M6 — Admin polish, release hardening, and operator readiness

**Goal**: Finish the release-facing surface of the Laravel/Filament rewrite: Admin operators can manage users, integrations, schedules, queues, and credentials from the UI; the single Docker image runs in a hardened production posture; release checks and operator documentation are complete.

**Outcome**: A new operator can clone the repository, follow `docs/install.md`, start the app with Docker Compose, bootstrap the first Admin, configure integrations, grant roles, inspect operational health, and run the supported role workflows without reading source code.

---

## Milestone Scope Alignment

M6 was originally written before M1-M5 implementation. The current codebase already includes Fortify 2FA, cumulative roles, audit/error logs, Redis queues, scheduler workers, source/tracker contracts, system and personal credential pages, sync/work-item workflows, triage commands, and attachments. M6 must build on those pieces rather than reintroduce alternate infrastructure.

M6 explicitly does **not** include the postponed M5 Defender for Cloud source. Defender-specific journeys, fixtures, source capabilities, documentation, and scan results remain out of scope until the Defender epic is resumed.

M6 must not add Laravel Horizon or a browser automation dependency. Queue and schedule visibility will use Laravel's existing database-backed queue tables, application logs, audit logs, sync runs, and Filament/Livewire pages. End-to-end validation will use the existing Pest, HTTP, and Livewire testing stack so the release gate remains maintainable in this project.

---

## Epic E1 — Admin Operations UI

### S1 — DB-backed integration settings and scheduler dispatcher
**Goal**: Admin can enable or disable each implemented source/tracker, set its fetch interval, choose the credential owner used by background jobs, test the connection, and see the last sync result.
**Context**: The current app uses `config/integration_settings.php` and environment variables for enablement and intervals. Registries only return enabled integrations, so disabled integrations cannot be managed from the UI. Scheduler entries are registered at boot from config and interval changes do not reliably take effect without restarting the scheduler worker.
**Solution**:
- Add migration `integration_settings` with `id`, `integration_kind` (`source` or `tracker`), `integration_id`, `enabled`, `fetch_interval_minutes`, `service_user_id` nullable FK to `users`, `last_synced_at`, `last_sync_status`, `last_sync_message`, timestamps, and unique `(integration_kind, integration_id)`.
- Add `App\Models\IntegrationSetting` and a small repository/service that merges DB rows with defaults from `config/integration_settings.php` for backward-compatible bootstrapping.
- Extend `App\Sources\Registry` and `App\Trackers\Registry` with an `all()` method returning every tagged implementation and make `enabled()` use `IntegrationSetting` instead of raw config.
- Replace per-integration schedule registration in `routes/console.php` with one minutely scheduler entry that dispatches a framework command or job such as `integrations:dispatch-due`.
- The dispatcher reads `integration_settings`, skips disabled integrations, dispatches due `FetchSourceJob` and tracker refresh jobs, and updates `last_synced_at`, `last_sync_status`, and `last_sync_message` from the job outcome.
- Refactor `RefreshWorkItemsJob` as needed so tracker intervals can be honored per tracker instead of refreshing every tracker on every tick.
- Add a Filament Admin page `Admin -> Integrations` that lists all known sources and trackers from the registries, with columns for kind, display name, enabled, interval, service user, last sync, and last status.
- Add row actions for edit settings and test connection. Test connection must run with the selected service user's credentials when present, otherwise system credentials.
- Record an audit row for every settings change and test-connection attempt.
**Definition of Done**:
- Admin can enable a disabled integration from the UI even when it was not enabled by environment config.
- Scheduler dispatch uses DB settings and observes enablement or interval changes on the next scheduler tick without restarting the container.
- Pest tests cover registry `all()` vs `enabled()`, enablement filtering, interval due/not-due behavior, service-user credential resolution, test connection, and audit rows.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pest --no-coverage` pass in the dev image.

### S2 — User lifecycle management and first-admin bootstrap
**Goal**: Admin can create users, assign roles, disable users, reset 2FA enrollment, and send password reset links without using Tinker.
**Context**: Users currently receive the `Reader` role by default and 2FA enrollment is enforced, but there is no Admin user resource, no disabled-user state, no first-admin bootstrap command, and the install docs still instruct operators to create an Admin through Tinker.
**Solution**:
- Add nullable/admin lifecycle columns to `users`: `is_disabled` boolean default false, `last_login_at` nullable timestamp, and `disabled_at` nullable timestamp.
- Add middleware that blocks disabled authenticated users on every web/Filament request, logs them out, invalidates their current session, and returns them to the login flow with a clear message.
- Track `last_login_at` on successful login using Laravel/Fortify events.
- Add Filament `UserResource` under `Admin`, authorized by `admin.users`.
- List columns: name, email, roles, disabled state, 2FA enabled state, last login.
- Create form: name, email, initial password, roles. If no role is selected, assign `Reader`.
- Edit form: name, email, roles, disabled state.
- Add row actions: reset 2FA, send password reset link, disable user, enable user.
- Reset 2FA clears `two_factor_secret`, `two_factor_recovery_codes`, and `two_factor_confirmed_at` so existing 2FA middleware forces re-enrollment on next login.
- Disabling a user deletes that user's database sessions and prevents future login until re-enabled.
- Add one-shot Artisan command `appsec:bootstrap-admin --email=... --password=... --name=...` that creates the first Admin and refuses to run when any user already exists.
- Record an audit row for user create, role changes, disable/enable, password reset link, reset 2FA, and bootstrap-admin.
**Definition of Done**:
- Admin can complete create, edit, role assignment, disable, enable, reset 2FA, and password reset actions from Filament.
- Bootstrap command creates the first Admin and fails fast when users already exist.
- Disabled users cannot log in and existing disabled-user sessions cannot access the app.
- Pest tests cover every user action, default Reader assignment, 2FA reset re-enrollment, disabled login denial, session invalidation, bootstrap success, bootstrap refusal, authorization, and audit rows.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pest --no-coverage` pass in the dev image.

### S3 — Native queue, schedule, and operations visibility
**Goal**: Admin can inspect background job health and trigger supported operational jobs without introducing Horizon.
**Context**: The Docker image already runs `schedule:work` and `queue:work` under Supervisor. The app has database queue tables, audit/error resources, sync-run widgets, and scheduled jobs, but no Admin page that ties these operational signals together.
**Solution**:
- Add a Filament Admin page `Admin -> Operations`, authorized by `admin.queue`.
- Show Supervisor-independent application state from Laravel data stores: queued job count, failed job count, recent failed jobs, recent sync runs, recent errors, and the configured schedule entries managed by AppSec Scout.
- Provide actions for safe one-off runs: dispatch due integrations now, dispatch a selected source fetch, dispatch tracker refresh for a selected tracker, prune audit logs, prune error logs, and update Trivy DB.
- Provide failed-job actions backed by Laravel queue APIs or Artisan commands: retry one failed job and forget one failed job.
- Surface all action outcomes through Filament notifications and audit rows.
- Keep failed job payloads readable but bounded; never render secrets or full serialized payloads in the table.
**Definition of Done**:
- Admin can view queue/schedule health and trigger each supported operation from Filament.
- Failed-job retry and forget actions work against Laravel's `failed_jobs` storage.
- Pest tests cover authorization, displayed counts, one-off dispatch actions, failed-job actions, secret redaction, notifications, and audit rows.
- No Horizon dependency is added.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pest --no-coverage` pass in the dev image.

---

## Epic E2 — Image Hardening and Release Gates

### S4 — Production container hardening
**Goal**: The production image runs with the least privileges compatible with nginx, php-fpm, Supervisor, Laravel storage, queues, scheduler, and triage binaries.
**Context**: The current Dockerfile already uses a multi-stage build, installs Trivy/JRE/BFG/Git, exposes `/up`, and Compose has a healthcheck. Supervisor still runs as root, writable runtime paths are broader than the final production posture requires, and Compose does not yet declare read-only filesystem/capability constraints.
**Solution**:
- Make the final container run as non-root `www-data` wherever practical, and document any process that must retain elevated privileges with a concrete reason.
- Adjust nginx, php-fpm, Supervisor, PID, cache, socket, and log paths so they work with a read-only root filesystem.
- Update `docker-compose.yml` for the production service with `read_only: true`, `cap_drop: ["ALL"]`, and explicit writable mounts or tmpfs entries for Laravel storage, framework cache/session/view paths, Supervisor runtime state, nginx/php temp paths, and `/tmp`.
- Keep port 8080 so `NET_BIND_SERVICE` is not required.
- Keep `/up` as the health endpoint and ensure the Dockerfile or Compose healthcheck uses `curl -fsS http://localhost:8080/up || exit 1`.
- Ensure `triage:trivy`, `triage:bfg`, and `triage:codesearch` still have writable isolated work directories under Laravel storage.
**Definition of Done**:
- `docker compose up --build -d` starts app, MySQL, and Redis successfully.
- Image runs successfully with read-only root filesystem, all Linux capabilities dropped, and no writable paths outside declared mounts/tmpfs.
- `/up` healthcheck passes.
- Queue worker and scheduler worker run under the intended unprivileged user.
- Existing triage command tests still pass in the dev image.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pest --no-coverage` pass in the dev image.

### S5 — SBOM, vulnerability, size, and CI release gates
**Goal**: The release process produces a verifiable image and fails on security or size regressions.
**Context**: The install docs already state the desired Trivy baseline, but enforcement is deferred. The repository needs a repeatable release gate for the production image rather than manual local checks.
**Solution**:
- Add or update CI workflow(s) to build the production Docker image and the dev image.
- Run Pint, PHPStan, and Pest against the dev image.
- Generate a CycloneDX SBOM for `appsec-scout:{tag-or-sha}` with Trivy.
- Run `trivy image --severity HIGH,CRITICAL --exit-code 1 appsec-scout:{tag-or-sha}`.
- Enforce image size under 600 MB using Docker image metadata.
- Store the SBOM as a CI artifact and document the release command sequence in `docs/operations.md`.
- Keep Trivy, OpenJDK 21, BFG 1.15.0, and the PHP/Node/Composer base images pinned to explicit major/minor lines; BFG SHA-256 verification remains required.
**Definition of Done**:
- CI fails when Pint, PHPStan, Pest, Trivy HIGH/CRITICAL scan, SBOM generation, or image-size checks fail.
- CI artifact includes `sbom.json` for the built image.
- Local operator docs include the equivalent manual commands.
- `docker images appsec-scout:latest` reports a size under 600 MB or the story explicitly reduces image contents until it does.

---

## Epic E3 — Release Validation

### S6 — Role workflow smoke suite
**Goal**: Automated release smoke covers each implemented role's main workflow without adding a browser automation framework.
**Context**: The current test suite already uses Pest, HTTP assertions, and Livewire tests for Filament resources and pages. Adding a separate browser plugin caused the previous M6 implementation attempt to drift and is not required to validate the supported release workflows.
**Solution**:
- Add a `Feature/Smoke` test group or Pest dataset that covers the implemented role journeys using HTTP, Filament Livewire components, jobs, queues, and fakes.
- Reader: login with enrolled 2FA, view dashboard data, alerts list, alert detail, containers, and linked software systems using seeded security events for all currently implemented event types.
- Triage: change state, change severity, add comment, run bulk state change, and verify dirty/pending badges and audit rows.
- Plan: create a single work item and a grouped work item against fake Jira/GitHub tracker implementations and verify link badges.
- Sync: open pending sync data, push pending state/severity to a fake source, verify dirty flags clear and sync/audit rows are written.
- Admin: bootstrap first Admin, create user, assign roles, reset 2FA, disable/enable user, edit integration settings, test connection, and dispatch an operations action.
- Exclude Defender-only event types and source capabilities until the deferred Defender epic is implemented.
- Add a dedicated Composer script or documented command for the smoke suite while keeping the full `vendor/bin/pest --no-coverage` gate green.
**Definition of Done**:
- Smoke suite covers Reader, Triage, Plan, Sync, and Admin role workflows using only supported implemented integrations and fakes.
- Tests assert authorization boundaries for at least one denied action per role.
- Tests run in CI and pass in the dev image.
- No browser automation package is added.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and full `vendor/bin/pest --no-coverage` pass in the dev image.

---

## Epic E4 — Operator Documentation

### S7 — Installation, administration, role, security, and architecture docs
**Goal**: Documentation lets an operator install, configure, run, administer, and troubleshoot AppSec Scout without reading source code.
**Context**: `docs/install.md` and `docs/operations.md` exist, but they still include a Tinker-based first-admin flow and do not yet cover Admin UI workflows, per-role workflows, architecture, security posture, release gates, or the deferred Defender scope.
**Solution**:
- Update `docs/install.md` with prerequisites, proxy/CA handling, `.env` setup, `docker compose up --build`, migrations, seeding, `appsec:bootstrap-admin`, first login, mandatory 2FA enrollment, and healthcheck verification.
- Update `docs/operations.md` with build/test/release commands, queue/scheduler behavior, Operations page usage, failed-job retry/forget, Trivy DB update, SBOM generation, Trivy image scan, image-size check, logs, backups, upgrades, and rollback notes.
- Add `docs/admin.md` covering users, roles, disabling users, 2FA reset, system credentials, personal credentials, integration enablement, intervals, service users, connection tests, audit logs, error logs, and operations health.
- Add role guides under `docs/roles/reader.md`, `docs/roles/triage.md`, `docs/roles/plan.md`, `docs/roles/sync.md`, and `docs/roles/admin.md`.
- Add `docs/architecture.md` with a GitHub-renderable Mermaid diagram showing sources -> fetch dispatcher/jobs -> DB -> Filament UI -> sync push jobs -> sources, tracker refresh/work-item creation, credentials, audit/error logs, queues, and scheduler.
- Add `docs/security.md` with threat model summary, credential storage, proxy/CA behavior, subprocess sandboxing for triage commands, authorization model, audit guarantees, container hardening posture, SBOM/vulnerability gates, and the Defender deferral.
- Ensure docs reference the canonical M1-M6 sequence and state that Defender for Cloud remains deferred from M6.
**Definition of Done**:
- A clean Docker host can follow `docs/install.md` to a working first Admin login without Tinker.
- Mermaid diagrams render on GitHub.
- Docs describe only implemented M1-M5 features plus M6 work; Defender is clearly marked deferred.
- Every role guide has a matching automated smoke path from S6.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pest --no-coverage` pass after documentation changes.

---

## Dependency Order

1. S1 integration settings must land before S3 Operations and S6 Admin smoke can fully validate integration management.
2. S2 user lifecycle must land before S6 Admin smoke and S7 install/admin docs can replace the Tinker bootstrap flow.
3. S3 Operations depends on S1's dispatcher model for integration run-now actions.
4. S4 hardening should land before S5 release gates so CI validates the final production posture.
5. S6 smoke tests should land before S7 docs are finalized so guides match tested workflows.

---

## Definition of Done — Milestone M6

- All stories' Definitions of Done are met.
- No M6 story depends on the postponed Defender for Cloud epic.
- Admin can manage users, roles, 2FA resets, disabled users, integration settings, service users, credentials, schedule dispatch, queues, failed jobs, audit logs, and error logs from Filament.
- Production image runs with read-only root filesystem, all Linux capabilities dropped, explicit writable mounts/tmpfs, passing `/up` healthcheck, queue worker, and scheduler worker.
- CI builds the dev and production images, runs Pint, PHPStan, Pest, role smoke tests, Trivy HIGH/CRITICAL scan, SBOM generation, and image-size check.
- Image size is under 600 MB and the Trivy HIGH/CRITICAL gate is clean or blocks release.
- Documentation is complete enough for a new operator to install, bootstrap, configure, administer, and troubleshoot AppSec Scout from `docs/install.md` and linked docs alone.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pest --no-coverage` are green in the dev image.
- Release tag `v1.0.0` is ready after the CI gates pass, the Docker image is pushed, SBOM is attached, and the changelog is updated.
