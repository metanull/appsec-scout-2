# M6 — Admin polish + packaging

**Goal**: Round out the Admin role with user/integration/queue management, harden the production Docker image, write end-to-end browser tests covering each role, and produce operator documentation.

**Outcome**: A new operator can stand up the app from `docker compose up`, follow the install guide, configure integrations, and grant roles — all without consulting the source code.

---

## Epic E1 — Admin UI

### S1 — Queue & schedule UI
**Goal**: Inspect queued/failed jobs; trigger one-off runs.
**Solution**:
- `composer require laravel/horizon`; mount at `/admin/horizon` behind Admin policy.
- Filament page `Admin → Schedule` lists all entries registered in `Console\Kernel::schedule`:
  - Columns: command, expression, last run, last status, next run.
  - Action "Run now" dispatches the corresponding job and surfaces a toast.
- Failed jobs accessible via Horizon's UI; manual retry per failed job from Horizon.
**Definition of Done**:
- Pest test for the "Run now" action against a fake schedulable command.
- Manual smoke: trigger a `FetchSourceJob` from the UI and observe Horizon picking it up.

### S2 — Integration enablement + interval
**Goal**: Per-source and per-tracker enable/disable + per-integration fetch interval + designated service user.
**Solution**:
- Migration `integration_settings` (id, integration_kind enum[source|tracker], integration_id varchar, enabled boolean, fetch_interval_minutes int, service_user_id FK nullable, last_synced_at timestamp nullable, last_sync_status varchar nullable, created_at, updated_at). Unique `(integration_kind, integration_id)`.
- Filament page `Admin → Integrations` listing all registered sources + trackers:
  - Toggle `enabled`.
  - Edit `fetch_interval_minutes` (5–1440).
  - Pick `service_user_id` (from users list).
  - "Test connection" button — uses the system PAT or designated service user's PAT.
  - Last sync badge.
- Scheduler honors `enabled = false` (skips dispatch).
**Definition of Done**:
- Pest tests for enablement filtering in scheduler dispatch.
- Pest tests for interval changes taking effect at next tick.
- Audit row on every change.

### S3 — User management + 2FA reset
**Goal**: Admin can create/disable users, assign roles, reset 2FA.
**Solution**:
- Filament resource `User` (Admin only):
  - List: name, email, roles (multi-badge), is_disabled, two_factor_enabled, last_login_at.
  - Create: name, email, initial password (one-shot), roles (multi-select).
  - Edit: name, email, roles, is_disabled.
  - Action "Reset 2FA": clears the user's `two_factor_secret` and `two_factor_recovery_codes`; forces re-enrollment on next login.
  - Action "Send password reset link": uses Fortify's reset flow.
- Default role on Admin-created user = `Reader` (unchanged from M1-S8).
- Disabling a user invalidates all their sessions (via `Auth::logoutOtherDevices` and a `users.is_disabled` middleware check on every request).
**Definition of Done**:
- Pest tests for create / disable / reset-2FA.
- Pest test asserts disabled user cannot log in.
- Audit row on every action.

---

## Epic E2 — Image Hardening & Packaging

### S4 — Image hardening + healthcheck
**Goal**: Production-ready Docker image.
**Context**: M1-S3 produced a working image; this story brings it to production quality.
**Solution**:
- Run as non-root `www-data` (uid 33). Drop all capabilities; add only `NET_BIND_SERVICE` if binding to <1024 (we bind to 8080 so this is not needed).
- `HEALTHCHECK CMD curl -fsS http://localhost:8080/up || exit 1` (Laravel's stock `/up` endpoint).
- Read-only root filesystem (`docker compose` setting); explicit writable mounts:
  - `/var/www/storage` (Laravel storage)
  - `/var/log/supervisor`
  - `/tmp` (tmpfs)
- Multi-stage build:
  - `composer-deps` (composer:2) → vendor/
  - `assets` (node:22-alpine) → public/build/
  - Final `php:8.4-fpm-bookworm-slim` with only runtime deps copied in.
- Image size target: < 600 MB (verified in CI).
- SBOM via `trivy image --format cyclonedx --output sbom.json` attached to release artifacts.
- CI gate: `trivy image appsec-scout:{tag}` must report 0 Critical, 0 High.
- Pin all binary versions: Trivy by major.minor, OpenJDK 21 LTS, BFG 1.15.0 with SHA-256 verification.
**Definition of Done**:
- `docker scout cves appsec-scout:latest` (or `trivy image`) — 0 Critical / 0 High.
- `docker images appsec-scout:latest` — size < 600 MB.
- CI workflow enforces both above gates and fails the build on regression.
- Image runs with `--read-only --cap-drop=ALL --user 33:33` without errors.

---

## Epic E3 — End-to-End Validation

### S5 — End-to-end Pest suite
**Goal**: Browser-driven smoke covering each role's main journey.
**Solution**:
- `composer require --dev pestphp/pest-plugin-browser` (or Laravel Dusk if browser plugin is unsuitable at time of writing).
- One scenario per role under `tests/Browser/`:
  - `ReaderJourneyTest`: login → 2FA enroll → 2FA login → dashboard → alerts list → alert detail (each event type once).
  - `TriageJourneyTest`: state edit single + bulk; assert dirty badge appears.
  - `SyncJourneyTest`: open pending-sync page; push to source (fake source backend); assert dirty cleared.
  - `PlanJourneyTest`: create single + grouped work item against fake tracker; verify link badges.
  - `AdminJourneyTest`: create user; assign roles; reset 2FA; toggle integration; test connection.
- E2E suite tagged `pest --testsuite=e2e`; runs against a dockerized MySQL + Redis spun up by the test harness.
- Fake sources/trackers registered in a dedicated E2E service provider, returning fixtures from `tests/Fixtures/`.
**Definition of Done**:
- All 5 scenarios green in CI.
- Manual run of `pest --testsuite=e2e --browser=chromium` passes locally.

---

## Epic E4 — Documentation

### S6 — Operator documentation
**Goal**: Install guide + admin guide + per-role guides + architecture diagram.
**Solution**:
- `docs/install.md` — prerequisites, `docker compose up`, env config, first admin bootstrap (a one-shot Artisan command `php artisan appsec:bootstrap-admin --email=... --password=...` that creates the initial admin user; refuses to run if any user already exists).
- `docs/admin.md` — system PATs, integrations, users, queue, scheduling, audit, error log.
- `docs/roles/reader.md` — dashboard, alerts list, alert detail navigation.
- `docs/roles/triage.md` — state changes, bulk edits, comments.
- `docs/roles/plan.md` — work item creation single + grouped, tracker selection, triage commands from UI.
- `docs/roles/sync.md` — review queue, push flow, retry behavior.
- `docs/roles/admin.md` — user/role management, integration setup, 2FA reset.
- `docs/architecture.md` — Mermaid diagram showing: sources → fetch jobs → DB → UI → push jobs → sources; tracker refresh job + work-item creation flow.
- `docs/security.md` — threat model summary, subprocess sandboxing approach, credential storage, audit guarantees.
**Definition of Done**:
- Docs reviewed; install guide validated by running through it from a clean VM (or a fresh Docker host).
- Mermaid diagrams render correctly on GitHub.
- Architecture document references the canonical M1–M6 sequence.

---

## Definition of Done — Milestone M6

- All stories' DoDs met.
- `docker scout cves` clean; image size < 600 MB; CI enforces both.
- Full E2E suite green in CI.
- Documentation complete; new operator can stand up the app from `docs/install.md` alone.
- `vendor/bin/pint --test` clean; `vendor/bin/pest` and `pest --testsuite=e2e` both green.
- Release tag `v1.0.0` ready: Docker image pushed to registry, SBOM attached, changelog updated.
