# AppSec Scout — Security Notes

This document summarizes the implemented security posture through M6.

## Threat Model Summary

Primary concerns:

- unauthorized access to alert data or admin functions
- exposure of upstream credentials
- accidental or implicit upstream mutation
- insecure command execution for triage tooling
- loss of operator accountability
- unnecessary container privileges

Core responses in the implemented app:

- Laravel Fortify authentication with mandatory TOTP enrollment
- Spatie permission-based role enforcement
- encrypted credential storage
- audit rows for write actions and operational actions
- explicit Sync-role-only upstream writeback
- constrained container runtime and fixed command execution paths

## Authentication And Session Controls

Authentication uses Laravel Fortify.

Implemented controls:

- email and password login
- mandatory two-factor enrollment before protected Filament access
- login rate limiting
- disabled-user enforcement that logs out the account and invalidates the session
- login timestamp tracking for successful logins

2FA reset is an Admin action and clears the stored secret, recovery codes, and confirmation timestamp.

## Authorization Model

Role and permission model is implemented with `spatie/laravel-permission`.

Roles:

- Reader
- Triage
- Plan
- Sync
- Admin

Authorization is enforced at page, resource, and action boundaries rather than by hiding the entire application shell from legitimate users.

## Credential Storage

Upstream credentials and personal access tokens are stored in the `credentials` table using Laravel encrypted casts.

Controls:

- no secrets are intentionally rendered back in plaintext in the UI
- connection tests update last-tested state without exposing secret values
- operations UI redacts sensitive keys from failed-job payload previews
- service-user credential selection is explicit per integration

## Proxy And CA Handling

Outbound HTTPS behavior is expected to honor the configured proxy and CA settings.

Direct internet environments should leave proxy variables and `SSL_CERT_FILE` unset or empty. In that mode, outbound clients use the container's default CA store.

Supported operator flow:

- export trusted host CAs into `.docker/certs/`
- set proxy environment variables before building
- let Docker build stages copy the CA material into runtime and build images

This supports Composer, npm, apt, curl, and the running application without host-side PHP reconfiguration.

## Triage Command Execution

The implemented triage command is `triage:codesearch`, which issues HTTP requests to the Azure DevOps code search API.

Security posture:

- no shell-string execution; outbound HTTP uses the configured Laravel HTTP client
- work item data is attached to the alert record and never executed
- the PAT is either passed explicitly via `--pat` for a single run or resolved from the system
  credential vault; it is never logged

## Audit Guarantees

Write and operator actions are expected to produce audit rows.

Examples:

- state and severity changes
- comments
- work-item creation and linking
- sync push attempts
- attachment changes
- user lifecycle actions
- integration settings changes
- operations page actions

Audit records support both operator troubleshooting and security review.

## Error Visibility

The app is intentionally fail-fast.

- errors are written to normal container logs
- application error records are persisted to `error_logs`
- operators can inspect these through the Admin `Errors` resource

This avoids silent degradation and keeps security-significant failures visible.

## Container Hardening

Implemented runtime posture:

- app container runs as `www-data`
- root filesystem is read-only
- all Linux capabilities are dropped
- runtime write locations are explicit mounts or tmpfs-backed paths
- nginx binds to port `8080`, so no privileged bind capability is needed

This is a least-privilege posture for the supported single-image runtime.

## Vulnerability And SBOM Gates

M6-E2-S5 is intentionally not implemented.

That means:

- CI does not build production images for release gating
- CI does not run Trivy image scans
- CI does not generate or publish an SBOM artifact

Local operators can still perform optional manual image inspection if their environment requires it, but the repository does not treat that as a required release gate.

## Defender Scope

Defender for Cloud remains deferred from M6. This document covers the implemented Laravel runtime, not the postponed Defender epic.