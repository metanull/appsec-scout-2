# Threat model

The OSS Scanner reads this file before it starts. It covers AppSec Scout: a Laravel 13 + Filament 5 application
that an application-security team self-hosts. It ingests security alerts from upstream scanners, triages them,
pushes state back, and files work items in issue trackers. It holds long-lived credentials (PATs and API tokens) for
every system it talks to, so it is a high-value target. [docs/security.md](../docs/security.md) describes the
intended controls.

## Where the code is

| Path | What it is |
| --- | --- |
| `/src/app-laravel` | The application, built. It contains `vendor/` with dev dependencies, `public/build/`, and a `.env` for a local site on `database/scan.sqlite`. |
| `/src/docker` | Container build files, entrypoints, and `lib/` install scripts for the production images (app, collector, static-analysis-collector, ops). |
| `/src/scripts` | PowerShell wrappers that operators run on their own workstation around Docker Compose. |

Start the site with `scout-start`, which listens on `http://127.0.0.1:8000/`. The panel is at the root.

**Seeded accounts:** one per role, all with the password `password`. Mandatory TOTP is already enrolled: the secret
is in `$SCAN_TOTP_SECRET`, and `scout-totp` prints the current code.

| Account | Role | Permissions (cumulative) |
| --- | --- | --- |
| `reader@example.com` | Reader | `alerts.view` |
| `triage@example.com` | Triage | + `alerts.edit`, `alerts.bulk-edit` |
| `plan@example.com` | Plan | + `work-items.create`, `work-items.link`, `context.curate`, `admin.repository-providers` |
| `sync@example.com` | Sync | + `work-items.sync`, `sources.push-state` |
| `admin@example.com` | Admin | + every `admin.*` permission |

**MCP server:** `MCP_USER_EMAIL=<email> php artisan mcp:start appsec-scout` runs the MCP server over stdio as that user.

**Tests:** run them from `/src/app-laravel` with `vendor/bin/pest --no-coverage [path]`. `phpunit.xml` forces sqlite
`:memory:`. Outbound HTTP is faked with `Http::fake`, and git, trivy and dotnet with `Process::fake`.

## Where untrusted input enters

- **Anonymous HTTP.** The surface is:
  - the Filament login, password-reset and TOTP challenge pages (Fortify; rate limits are in `FortifyServiceProvider`)
  - the Entra ID OIDC redirect and callback (`EntraLoginController`, only when `ENTRA_ENABLED=true`)
  - `/up`
- **Authenticated users of a lower role.** A Reader or Triage user must not reach another tier's actions. Those
  actions include changing upstream state, reading or replacing credentials, managing users, and reading the audit
  log. Authorization runs through Spatie permissions, policies and Filament's `can*()` hooks.
  `User::canAccessPanel()` decides only whole-account access (disabled or suspended accounts).
- **Data from upstream systems.** These systems are AzDO Advanced Security, AppScan on Cloud, Detectify, GitHub,
  Jira, Bitbucket and Dependency-Track. Treat their API responses as untrusted: alert titles, descriptions, file
  paths, repository names, clone URLs, pagination `next` links, SARIF/SBOM documents and Trivy output. Anyone who can
  land code or open an alert in a scanned project controls some of this content. It is then rendered to staff in the
  panel and echoed into tracker issues (Jira ADF, GitHub Markdown).
- **Repositories cloned by the collectors.** `CollectRepositoryJob` and `AnalyzeRepositoryJob` clone repositories
  and run `trivy`, `dotnet restore/build`, Roslynator, SpotBugs, Opengrep, and the repository's own
  `mvnw`/`gradlew`. Running the repository's build is by design, and it happens inside the isolated
  static-analysis-collector container. Escaping that container, reaching the app's database or vault, or stealing
  another integration's credential from there is in scope. So is argument injection into `git`/`trivy` from a URL
  or branch name.
- **Uploaded attachments.** Filament `FileUpload` on alerts and assets, stored in the database and served by the
  download controllers in `routes/web.php`.
- **Admin-entered integration settings.** Base URLs, hosts and organisations are stored in the vault and become
  outbound request targets. SSRF from a role below Admin, or a credential sent to a host other than the one it was
  stored for, is in scope.

## Components that matter most

1. **The credential vault** (`app/Credentials/`). Secrets are encrypted with the `encrypted` cast under `APP_KEY`, and
   user and system credentials are scoped separately. Any path that reveals a secret is high or critical. That
   includes UI text, logs, exceptions, audit-log `old/new` values, error-log rows, MCP tool output, tracker issue
   bodies, process argv or environment visible to other users, and leftover `.netrc`/`.git-credentials` files. So is
   one user resolving another user's personal credential.
2. **Authentication.** In scope:
   - bypassing mandatory TOTP (including `EnsureMultiFactorAuthenticationIsEnabledForLocalSessions` and its Entra exemption)
   - Entra account linking by e-mail, id_token claim handling, and roles synced from the `roles` claim
   - disabled-account enforcement (`EnsureUserIsEnabled`)
   - session fixation
3. **Authorization of write actions and their audit trail.** Every write action must produce an `AuditLog` entry
   with its actor. A write that skips the audit, or a way to forge or alter audit rows, is in scope.
4. **Outbound integrations and Sync** (`app/Sources/`, `app/Trackers/`, `app/SourceControl/`, `app/Sync/`,
   `app/Http/OutboundHttpFactory.php`): SSRF, credential leakage across hosts, and injection into tracker payloads.
5. **The MCP server** (`app/Mcp/`, stdio only). It trusts `MCP_USER_EMAIL` as the actor. Each tool must still
   enforce that user's permissions and audit its writes.

## Components that matter less, or are out of scope

- `legacy-code/` (a git submodule of another project, not cloned), `plan/` and `tools/` are out of scope.
- The `ops` image (`docker/ops/`) is a local-only developer sandbox on the operator's own Docker Desktop and is
  never deployed. Its use of `claude --dangerously-skip-permissions` is by design. Issues there are low unless they
  reach the host.
- The PowerShell scripts in `scripts/` run on the operator's own workstation with their own privileges. They are
  low priority.
- `.env.example` and the fixed TOTP secret and `password` accounts in this scan image are test fixtures, not
  findings.

## How we rate severity

- **Critical.** Remote code execution in the app container. Authentication bypass. Unauthenticated access to any
  data. Disclosure of a stored credential to anyone who did not store it, or to anyone below Admin for a system
  credential.
- **High:**
  - privilege escalation between roles
  - bypassing mandatory TOTP
  - SQL injection behind login
  - stored XSS from upstream alert content that runs in a staff session
  - SSRF from a non-Admin role
  - escaping the collector container into the app's data
  - a write action with no audit record
- **Medium:**
  - information disclosure across permission tiers short of credentials
  - CSRF on a state-changing action
  - Admin-only SSRF to internal hosts
  - injection into tracker issues that misleads but does not execute
- **Low:** denial of service that needs an authenticated user, missing hardening headers, and verbose errors.

## Anything to leave alone

- `APP_DEBUG=true`, the `php artisan serve` web server and the seeded accounts in this image are artefacts of the
  scan setup. Production runs nginx + php-fpm as `www-data` on a read-only root filesystem.
- The static-analysis collector running a cloned repository's own build tooling is by design (see above).
- Artisan commands that print or export credentials (`credentials:system:*`, `vault:*`) are operator tools that need
  shell access to the container. Report them only if they are reachable some other way.
