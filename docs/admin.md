# AppSec Scout — Admin Guide

This guide covers the Admin role surfaces implemented in M6.

## Admin Responsibilities

Admin users manage:

- user lifecycle
- roles
- disabled account enforcement
- 2FA resets
- system credentials
- integration settings
- queue and scheduler visibility
- failed job handling
- audit and error log review

## Users

Use `Admin -> Users` to manage operator accounts.

Available actions:

- create a user
- assign one or more cumulative roles
- edit name or email
- disable a user
- enable a user
- reset 2FA enrollment
- send a password reset link

Behavior details:

- If no role is selected on create, the user receives `Reader`.
- Disabling a user marks the account disabled, deletes server-side sessions, and blocks future access.
- Resetting 2FA clears the current TOTP secret and recovery codes so the next login is forced through enrollment again.
- User lifecycle actions write audit rows.

## Roles

Role model:

- `Reader`
- `Triage`
- `Plan`
- `Sync`
- `Admin`

Roles are cumulative through the seeded Spatie permission mapping.

Practical effect:

- `Admin` includes the lower-level permissions already granted by prior roles.
- Authorization still happens at each resource, page, and action boundary.

## System Credentials

Use `Admin -> System credentials` to store shared credentials for sources and trackers.

System credentials are used when:

- an integration has no service user configured
- no personal credential is supplied for an interactive action
- the workflow explicitly resolves the system-owned secret

Personal credentials are managed by each signed-in user from `Profile integrations`.

## Integrations

Use `Admin -> Integrations` to manage every known source and tracker, including integrations that are currently disabled.

Per integration you can:

- enable or disable it
- set the polling interval in minutes
- choose a service user for background credential resolution
- run a connection test
- inspect the last sync timestamp and status

Important behavior:

- Scheduler decisions are database-backed and applied on the next minutely dispatcher tick.
- No scheduler restart is needed after changing enablement or interval values.
- Connection tests run with the selected service user's credentials when configured; otherwise they use system credentials.

## Operations

Use `Admin -> Operations` for live background health and one-off operational actions.

The page shows:

- queued job count
- failed job count
- recent failed jobs
- recent sync runs
- recent error records
- managed schedule entries

Supported actions:

- dispatch due integrations
- dispatch one source fetch
- dispatch one tracker refresh
- prune audit logs
- prune error logs
- queue a Trivy DB update
- retry a failed job
- forget a failed job

Failed-job payload previews are redacted and truncated before display.

## Audit And Error Logs

Use the Admin resources:

- `Audit Log`
- `Errors`

Audit log intent:

- record write actions and operational actions with actor context
- support troubleshooting and operator accountability

Error log intent:

- persist application failures in the database
- surface operational issues without requiring container shell access

## First Admin Bootstrap

Initial admin creation is command-driven:

```bash
docker compose exec app php artisan appsec:bootstrap-admin \
  --name="Admin" \
  --email="admin@example.com" \
  --password="changeme-now"
```

The command fails once any user already exists.

## Disabled User Handling

Disabled-user behavior is enforced across web and Filament requests:

- the user is logged out
- the session is invalidated
- access is redirected back to the login flow with a clear error message

This is a whole-account control. Feature authorization still uses normal role and permission checks.

## Deferred Scope

Defender for Cloud administration remains outside M6. This guide covers only the implemented M1 to M6 Laravel features.