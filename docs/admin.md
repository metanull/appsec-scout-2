# AppSec Scout — Admin Guide

This guide covers the Admin role's surfaces in the Filament panel.

## Admin Responsibilities

Admin users manage:

- User lifecycle
- Roles
- Disabled account enforcement
- Multi-factor authentication resets
- System credentials
- Integration settings
- Queue and scheduler visibility
- Failed job handling
- Audit and error log review

## Users

Use `Admin -> Users` to manage operator accounts.

Available actions:

- Create a user.
- Assign one or more cumulative roles.
- Edit name or email.
- Disable a user.
- Enable a user.
- Reset multi-factor enrollment.
- Send a password reset link.

Behavior details:

- If no role is selected on create, the user receives `Reader`.
- Disabling a user marks the account disabled, deletes server-side sessions, and blocks future
  access.
- Resetting multi-factor enrollment clears the current TOTP secret and recovery codes so the next
  login is forced through enrollment again.
- User lifecycle actions write audit rows.

## Roles

Role model, cumulative through the seeded Spatie permission mapping:

- `Reader`
- `Triage`
- `Plan`
- `Sync`
- `Admin`

`Admin` includes the lower-level permissions already granted by prior roles. Authorization still
happens at each resource, page, and action boundary — see [docs/roles/](roles/) for the full
per-role breakdown, including two capabilities held by Plan/Sync/Admin (not just Admin):
`context.curate` (Software Asset creation/editing, Curated Links) and
`admin.repository-providers` (Repository Provider management, filed under the Admin navigation
group despite not being Admin-only).

## System Credentials

Use `Admin -> System Credentials` to store shared credentials for sources, trackers, and Source
Control providers.

System credentials are always used by system-triggered operations — scheduled sync, background
jobs, and bulk Ops-page actions — never by trying a personal credential first. Personal credentials
are managed by each signed-in user from `Profile -> Integrations`, and are used only for that
user's own interactive actions. A missing required credential fails with a clear error rather than
falling back to something else.

Connection tests use the same outbound HTTP factory as source sync and tracker actions. In direct
internet environments, leave proxy and custom CA settings empty. In corporate SSL-inspection
environments, configure the proxy and mounted CA bundle as described in
[docs/install.md](install.md#corporate-proxy-and-ssl-inspection).

ASoC credentials require a regional base URL in addition to `keyId` and `keySecret`. Use
`https://cloud.appscan.com/` for US tenants and `https://eu.cloud.appscan.com/` for EU tenants.

## Integrations

Use `Admin -> Integrations` to manage every known Source, Tracker, and Source Control provider,
including ones that are currently disabled.

Per integration you can:

- Enable or disable it.
- Set the polling interval in minutes.
- Run a connection test.
- Inspect the last sync timestamp and status.

Important behavior:

- Scheduler decisions are database-backed and applied on the next minutely dispatcher tick — no
  scheduler restart is needed after changing enablement or interval values.
- Connection tests always run with the system credential.
- Source Control rows show the same enable/interval fields, but nothing reads them: Source Control
  never runs on a schedule (see [docs/concepts/integration.md](concepts/integration.md#source-control-is-not-scheduled)).

## Operations

Use `Admin -> Operations` for live background health and one-off operational actions.

The page shows:

- Queued job count, failed job count, recent failed jobs.
- Recent sync runs and recent error records.
- Reconciliation and inventory-sync last-run summaries.
- SBOM scan status (recent SbomScan/StaticAnalysis runs).
- Managed schedule entries.

Actions:

- Dispatch due integrations.
- Dispatch one source fetch, or one tracker refresh.
- Reconcile all tracker links (`ReconcileAllJob`, sweeps every alert for missing work-item links).
- Sync inventory (`SyncInventoryJob`, syncs `SoftwareSystem`/`SecurityContainer` rows from every
  enabled Source and Source Control provider that supports it).
- Prune audit logs, prune error logs.
- Retry a failed job, or forget it.

Every action writes an audit row. Failed-job payload previews are redacted and truncated before
display.

## Audit And Error Logs

Use the Admin resources:

- `Audit Log` — records write actions and operational actions with actor context, for
  troubleshooting and operator accountability.
- `Errors` — persists application failures in the database, surfacing operational issues without
  requiring container shell access.

## First Admin Bootstrap

The container entrypoint bootstraps the first admin automatically on first start (see
[docs/install.md](install.md#quick-start-recommended-path)). To bootstrap one manually:

```bash
docker compose exec app php artisan appsec:bootstrap-admin \
  --name="Admin" \
  --email="admin@example.com" \
  --password="changeme-now"
```

The command fails once any user already exists, unless `--if-missing` is passed.

## Disabled User Handling

Disabled-user behavior is enforced on every web and Filament request:

- The user is logged out.
- The session is invalidated.
- Access is redirected back to the login flow with a clear error message.

This is a whole-account control — feature authorization still uses normal role and permission
checks.

## Out of Scope

Defender for Cloud > DevOps has no administration surface — see
[docs/concepts/sources-trackers-source-control.md](concepts/sources-trackers-source-control.md#supported-vs-deferred).
