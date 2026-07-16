# AppSec Scout — Security Notes

This document summarizes the implemented security posture.

## Threat Model Summary

Primary concerns:

- Unauthorized access to alert data or admin functions.
- Exposure of upstream credentials.
- Accidental or implicit upstream mutation.
- Insecure command execution for triage tooling.
- Loss of operator accountability.
- Unnecessary container privileges.

Core responses:

- Laravel Fortify for password authentication, plus Filament's native panel-level mandatory
  multi-factor authentication.
- Spatie permission-based role enforcement.
- Encrypted credential storage.
- Audit rows for write actions and operational actions.
- Explicit Sync-role-only upstream writeback.
- Constrained container runtime and fixed command execution paths.

## Authentication And Session Controls

Password authentication is handled by **Laravel Fortify** (`App\Providers\FortifyServiceProvider`):
the login callback, login view, and rate limiters (`login`, `two-factor`) — 5 attempts per minute,
keyed by email+IP for login and by the pending login session id for the second factor.

**Mandatory multi-factor authentication is enforced by Filament itself**, not Fortify: the panel is
configured with `->multiFactorAuthentication([AppAuthentication::make()->recoverable()],
isRequired: true)` (`App\Providers\Filament\AppSecScoutPanelProvider`). A user cannot reach any
protected page until they've enrolled an authenticator-app TOTP code; Filament redirects to its own
setup page automatically until enrollment is confirmed. `User` implements `HasAppAuthentication`/
`HasAppAuthenticationRecovery`, storing the TOTP secret, confirmation timestamp, and recovery codes
encrypted on the model.

Other controls:

- Disabled-user enforcement (`EnsureUserIsEnabled` middleware): a disabled user is logged out,
  their session is invalidated and its token regenerated, and they're redirected to login with an
  explanatory error — on every request, not just at login.
- Login timestamp tracking for successful logins.

2FA reset is an Admin action that clears the stored secret, recovery codes, and confirmation
timestamp, forcing re-enrollment on next login.

## Authorization Model

Role and permission model implemented with `spatie/laravel-permission`. Roles, cumulative
(`Reader ⊂ Triage ⊂ Plan ⊂ Sync ⊂ Admin`): Reader, Triage, Plan, Sync, Admin — see
[docs/roles/](roles/) for the full per-role permission and surface breakdown.

Authorization is enforced at page, resource, and action boundaries (Filament `canViewAny()`/
`canCreate()`/`canEdit()`/`canDelete()` hooks, `Gate::allows()`/`Gate::authorize()` on individual
actions) rather than by hiding the entire application shell from legitimate users.

## Credential Storage

Upstream credentials and personal access tokens are stored in the `credentials` table using
Laravel's `encrypted` Eloquent cast (`Credential::$casts['value']`). Controls:

- No secret is intentionally rendered back in plaintext in the UI.
- Connection tests update last-tested state without exposing secret values.
- The Operations page redacts sensitive keys from failed-job payload previews.
- Dependency-Track's own API key (`dependencytrack.apiKey`) is provisioned automatically by the
  `dependencytrack-bootstrap` one-shot container and stored in the same vault — no manual entry.
- The shared token between `trivy-server` and Dependency-Track's Trivy analyzer is generated once,
  inside the stack, into a Docker volume neither container exposes externally.

## Proxy And CA Handling

Outbound HTTPS behavior honors the configured proxy and CA settings across every container in the
stack (`app`, `mysql`, `dependencytrack-apiserver`, `trivy-server`, `ops`). Direct-internet
environments leave the proxy variables and `SSL_CERT_FILE` unset or empty, and every container
falls back to its own default CA store (OS trust store, or the JRE's own `cacerts` for the two Java
services). `scripts/appsec-scout.ps1 -Rebuild` exports trusted host CA certificates into
`.docker/certs/` automatically; the build stages copy that material into every image so Composer,
npm, apt, curl, and the running app all trust the same chain without host-side reconfiguration —
see [docs/install.md](install.md#corporate-proxy-and-ssl-inspection).

## Triage Command Execution

`triage:codesearch` issues HTTP requests to the Azure DevOps code search API. Security posture:

- No shell-string execution; outbound HTTP uses the configured Laravel HTTP client.
- The result is attached to the alert record as data, never executed.
- The PAT is either passed explicitly via `--pat` for a single run or resolved from the
  `azdo-repos.pat` system credential; it is never logged.

The `ops` container (`invoke-ops.ps1`) runs the equivalent org-wide flows — SbomScan,
StaticAnalysis, and an autonomous Claude Code task mode — in a separate, sandboxed image with its
own PATs (GitHub/AzDO, layered from `docker/ops/.env` and appsec-scout's own credential vault) and
resource limits, isolated from the `app` container.

## Audit Guarantees

Every write and operator action produces an audit row, including: state and severity changes,
comments, work-item creation/linking/reconciliation, sync push attempts, attachment changes, user
lifecycle actions, integration settings changes, and Operations-page actions.

Audit records support both operator troubleshooting and security review.

## Error Visibility

The app is fail-fast by design:

- Errors are written to normal container logs.
- Application error records are persisted to `error_logs`.
- Operators inspect these through the Admin `Errors` resource.

## Container Hardening

The `app` and `dependencytrack-bootstrap` containers run as `www-data`, with a read-only root
filesystem, all Linux capabilities dropped, and explicit writable mounts/tmpfs for runtime paths.
nginx binds to port `8080`, so no privileged bind capability is needed. The `ops` container is a
separate, purpose-built image for sandboxed host-triggered scans and automation, not part of the
always-on stack (opt-in via the `ops` Compose profile).

## Vulnerability And SBOM Scanning

CI (`.github/workflows/laravel-ci.yml`) runs Pint, PHPStan, and Pest on a bare PHP runner; it does
not build a production image, run a Trivy image scan, or publish an SBOM as part of that workflow.

Separately, the running application includes a full, always-on SBOM/vulnerability scanning
pipeline: a self-hosted Trivy server, an OWASP Dependency-Track instance for visualization, and
host-triggered `invoke-ops.ps1 -SbomScan`/`-StaticAnalysis` workflows that scan every repository in
an Azure DevOps organization and import results as Local Findings, Dependencies, and (via a queued
listener) Dependency-Track SBOM uploads — see
[docs/concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md) for the full
pipeline. This is a scanning/visualization capability, not a CI release gate.

## Out of Scope

Defender for Cloud > DevOps is specified as a planned Source but has no runtime code — see
[docs/concepts/sources-trackers-source-control.md](concepts/sources-trackers-source-control.md#supported-vs-deferred).
