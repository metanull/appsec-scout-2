# M1 — Foundation

**Goal**: Production-shaped Laravel application running in a hardened Docker container, with authentication, role-based authorization, MySQL persistence, queues, audit log, error log, and encrypted credential storage. **No security data yet** — the platform itself.

**Outcome**: An operator can `docker compose up`, log in with email/password + TOTP, see an empty Filament dashboard, and confirm the five roles exist.

---

## Epic E1 — Project Scaffold

### S1 — Laravel + Filament scaffold
**Goal**: Bootstrap a Laravel 13 application with Filament 5 panel installed at `/`.
**Context**: First commit; no prior PHP project structure.
**Solution**:
- `composer create-project laravel/laravel .` at workspace root in a new top-level `app-laravel/` directory (kept separate from prior `app/`, `core/`, `dotnet/`).
- `composer require filament/filament:^5.0`.
- `php artisan filament:install --panels`.
- Provider `App\Providers\Filament\AppSecScoutPanelProvider` mounts a single panel at `/` (no separate admin sub-path).
- Configure `pint.json` (Laravel preset + `concat_space: { spacing: one }`), `phpstan.neon` (level 8), `pest.xml` (parallel by default).
- `.editorconfig` aligning with prior iterations (LF, UTF-8, 4-space PHP, 2-space YAML/JSON).
**Definition of Done**:
- `composer install && php artisan serve` boots; Filament login renders at `/`.
- `vendor/bin/pint --test` clean.
- `vendor/bin/pest` runs (with the single bundled smoke test).
- CI workflow `.github/workflows/laravel-ci.yml` runs Pint + Pest on PR.
**Relevant files**: n/a (greenfield).

### S2 — MySQL baseline migrations
**Goal**: MySQL 8.0+ as canonical database; baseline tables in place.
**Context**: Foundation for all domain tables in M2.
**Solution**:
- `.env.example` with `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_DATABASE=appsec_scout`.
- Document MySQL 8.0+ requirement in `plan/README.md` and root `README.md`.
- Ship stock Laravel migrations: `users` (with `email_verified_at`), `password_reset_tokens`, `sessions`, `jobs`, `failed_jobs`, `cache`.
**Definition of Done**:
- `php artisan migrate:fresh` succeeds against a real MySQL 8.0 container.
- Pest test boots Laravel against MySQL (no SQLite fallback anywhere).

---

## Epic E2 — Platform

### S3 — Docker image
**Goal**: Single image with PHP runtime + supervisord + all triage binaries pre-installed.
**Context**: Triage commands (M5) require trivy, JRE, BFG, git pre-installed; no sidecar pattern.
**Solution**:
- `docker/Dockerfile` multi-stage:
  - Stage `composer-deps`: `composer:2` resolves vendor.
  - Stage `assets`: `node:22-alpine` builds Filament assets (`npm ci && npm run build`).
  - Final stage: `php:8.4-fpm-trixie`. apt-installs: `nginx`, `supervisor`, `git`, `openjdk-21-jre-headless`, `ca-certificates`, `curl`, `unzip`, `libicu-dev`, PHP extensions via `docker-php-ext-install`: `pdo_mysql`, `intl`, `bcmath`, `opcache`, `zip`, `pcntl`, `redis` (via pecl).
  - Install Trivy via official apt repo.
  - Download BFG 1.15.0 jar (verified SHA-256) to `/opt/bfg/bfg.jar`.
  - Non-root user `www-data` (uid 33).
- `docker/supervisord.conf` programs: `php-fpm`, `nginx`, `schedule` (`php artisan schedule:work`), `queue` (`php artisan queue:work --tries=3 --max-time=3600`).
- `docker/nginx.conf` front for php-fpm with `/up` healthcheck route exposed.
- `docker-compose.yml`: services `app`, `mysql:8.0`, `redis:7-alpine` with named volumes for MySQL data and Laravel storage.
**Definition of Done**:
- `docker compose up --build` boots all three services.
- `docker compose exec app trivy --version`, `java -version`, `git --version`, `test -f /opt/bfg/bfg.jar` all succeed.
- `curl http://localhost:8080/login` returns 200.
- Image SBOM emitted via `trivy image` in CI; documented in `docs/install.md` (skeleton).

### S4 — HTTP proxy config layer
**Goal**: Single config block consumed by every outbound HTTP client.
**Context**: Enterprise networks require corporate proxy; all source/tracker clients must honor it consistently.
**Solution**:
- `config/proxy.php` reads `HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY` env vars.
- `App\Http\OutboundHttpFactory::create(array $defaults = []): \GuzzleHttp\Client` returns a client configured with `proxy`, `verify`, `no_proxy` from config.
- All source/tracker clients (M2+) receive this factory via constructor DI — they MUST NOT `new \GuzzleHttp\Client()` themselves.
- Pint custom rule (or PHPStan rule via `phpstan-strict-rules`) forbidding direct Guzzle instantiation outside the factory.
**Definition of Done**:
- Pest test using `Http::fake()` with proxy assertion verifies the proxy is applied when configured and omitted when empty.
- Pest test asserts `NO_PROXY` patterns honored (host, suffix, comma list).

### S5 — Audit log
**Goal**: Persistent audit trail of every write.
**Context**: Required for compliance and Sync traceability.
**Solution**:
- Migration `audit_logs` (id, user_id NULL, actor_kind enum[user|job|cli|system], action varchar, subject_type varchar, subject_id varchar, payload_json json, ip nullable, created_at). Indexes on `(subject_type, subject_id)` and `(user_id, created_at)`.
- `App\Audit\Recorder` service with one method per category — `recordStateChange`, `recordSyncPush`, `recordWorkItemCreated`, `recordAdminAction`, `recordCredentialChange`. No reflection or automatic interception.
- Filament resource at Admin → Audit Log with filters by user/action/date/subject; read-only.
- Pruning scheduled job retains 365 days by default (configurable).
**Definition of Done**:
- Pest tests assert each `Recorder` method writes the expected row.
- PAT values masked in payload (assertion in test).

### S6 — In-app error log surface
**Goal**: Admins see runtime errors without SSH.
**Context**: Operators may not have file-log access in the deployment environment.
**Solution**:
- Migration `error_logs` (id, level varchar, channel varchar, message text, context_json json, trace longtext, occurred_at timestamp). Index on `(level, occurred_at)`.
- Custom Monolog handler (registered in `config/logging.php`) writes `ERROR+` entries to `error_logs`.
- Filament resource at Admin → Errors with pagination and filtering.
- Pruning scheduled job retains 90 days by default (configurable).
**Definition of Done**:
- Pest test triggers an exception in a controller, asserts row inserted.
- Older entries pruned by scheduled job (Pest test for the pruning job).

---

## Epic E3 — Security Primitives

### S7 — Authentication via Fortify with mandatory TOTP 2FA
**Goal**: Email/password login + mandatory TOTP enrollment after first authentication.
**Context**: Operator app handling security data — 2FA is non-negotiable; SSO explicitly out of scope.
**Solution**:
- `composer require laravel/fortify`.
- `config/fortify.php`: `features => [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])]`. Disable registration.
- Integration with Filament login form via `filament/fortify` glue (or hand-rolled adapter if the glue package is unavailable at time of writing).
- Middleware `App\Http\Middleware\RequireTwoFactor` redirects to `/user/two-factor-authentication` until the user has confirmed TOTP. Registered on the Filament panel.
- Recovery codes downloadable as `.txt` (one-shot view after enrollment).
- Login throttling via the stock Fortify `LoginRateLimiter` (5 attempts / minute / IP+email).
**Definition of Done**:
- Pest tests: (a) new user must enroll 2FA before reaching `/`; (b) existing 2FA user authenticates with valid TOTP; (c) invalid TOTP rejected; (d) recovery code works once; (e) throttling triggers after 5 failures.

### S8 — Roles + permissions
**Goal**: Five cumulative roles + permission enforcement on Filament resources.
**Context**: Each operator persona has explicit capabilities (TASK.md mandate).
**Solution**:
- `composer require spatie/laravel-permission`.
- `php artisan vendor:publish --tag=permission-config` + run published migration.
- Database seeder `RolePermissionSeeder` creates roles `Reader`, `Triage`, `Plan`, `Sync`, `Admin` and permissions:
  - `alerts.view`, `alerts.edit`, `alerts.bulk-edit`
  - `work-items.create`, `work-items.link`, `work-items.sync`
  - `sources.push-state`
  - `admin.users`, `admin.system-pats`, `admin.queue`, `admin.audit`, `admin.errors`, `admin.integrations`
  - `triage.run-trivy`, `triage.run-bfg`, `triage.run-codesearch`
- Cumulative inheritance via a domain rule applied at seed: Triage gets Reader's perms; Plan gets Triage's; Sync gets Plan's; Admin gets all.
- Default role on user creation = `Reader`.
- Filament resource policies: `viewAny`, `view`, `update`, `delete` mapped to the relevant permission.
**Definition of Done**:
- Pest tests parameterized over each role asserting visible/forbidden pages.
- `php artisan db:seed` idempotent (re-run produces same state).

### S9 — Encrypted credential vault
**Goal**: Centralized, encrypted storage for source and tracker credentials.
**Context**: Per-user PATs (M4-S11) and system PATs (M4-S12) share infrastructure.
**Solution**:
- Migration `credentials` (id, owner_user_id NULL=system, integration_key varchar e.g. `azdo.pat`, value text [encrypted cast], description varchar nullable, last_tested_at timestamp nullable, last_tested_ok boolean nullable, last_tested_error text nullable, created_at, updated_at). Unique index `(owner_user_id, integration_key)`.
- Eloquent model `App\Credentials\Credential` with `casts => ['value' => 'encrypted']`.
- Service `App\Credentials\Vault` exposing `get(string $key, ?int $userId): ?string`, `set(string $key, ?int $userId, string $value, ?string $description = null): void`, `test(string $key, ?int $userId, callable $probe): TestResult`.
- Values never logged: `Recorder::recordCredentialChange` records key + actor + outcome only (test in S5).
**Definition of Done**:
- Pest test reads raw DB row, asserts ciphertext != plaintext.
- Pest test for set→get round-trip.
- Pest test asserts audit row written on set with value redacted.

---

## Definition of Done — Milestone M1

- All stories' DoDs met.
- `docker compose up` boots cleanly; login + 2FA enrollment + role-gated access manually verified.
- `vendor/bin/pint --test` and `vendor/bin/pest` both pass in CI.
- `trivy image appsec-scout:latest` baseline scan recorded (target enforcement deferred to M6-S4).
- `docs/install.md` (skeleton) describes how to launch the empty app.
