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
- "Send a password reset link" mails a signed link to the panel's reset page (users can also
  request one themselves via "Forgot password" on the login page). With the default
  `MAIL_MAILER=log`, the mail — link included — is written to the Laravel log instead of being
  delivered; configure SMTP for real delivery (see
  [docs/install.md](install.md#outbound-mail)). The action is hidden for federated users, who
  have no password.
- User lifecycle actions write audit rows.
- **Federated (Entra) users**: their roles are managed in Entra (App Role assignments) and
  re-synced at every login — local role edits to a federated user are overwritten at their next
  sign-in. They have no password, so "Send a password reset link" and the profile password
  section do not apply; the TOTP reset action is only relevant to password-authenticated
  sessions. See [docs/security.md](security.md#entra-id-federated-sign-in-optional).

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

System credentials are used by system-triggered operations — scheduled sync, background jobs, and
bulk Ops-page actions. Personal credentials are managed by each signed-in user from
`Profile -> Integrations`, and are used only for that user's own interactive actions. A missing
required credential fails with a clear error.

Connection tests use the same outbound HTTP factory as source sync and tracker actions. In direct
internet environments, leave proxy and custom CA settings empty. In corporate SSL-inspection
environments, configure the proxy and mounted CA bundle as described in
[docs/install.md](install.md#corporate-proxy-and-ssl-inspection).

ASoC credentials require a regional base URL in addition to `keyId` and `keySecret`. Use
`https://cloud.appscan.com/` for US tenants and `https://eu.cloud.appscan.com/` for EU tenants.

The page also has a **Dependency-Track** section for the two vault keys the app itself consumes:
the API server base URL (`dependencytrack.baseUrl`, in-cluster default
`http://dependencytrack-apiserver:8080`) and the automation-team API key
(`dependencytrack.apiKey`). Both are auto-provisioned by the `dependencytrack-bootstrap`
container on first start; edit them here when pointing the app at a Dependency-Track instance
hosted elsewhere. The Test action calls the Dependency-Track API with the stored key.
`dependencytrack.adminPassword` (Dependency-Track's own UI login) is intentionally not exposed in
the UI — it remains reachable via the `vault:get` CLI only.

## Integrations

Integrations are not scheduled and have no enable/disable or interval settings. Every registered
Source, Tracker, and Source Control provider is always available; you sync them on demand.

- Configure their credentials on `Admin -> System Credentials` (system-wide) or
  `Profile -> Integrations` (your own personal override). Both pages also run a connection test,
  always with the credential being edited.
- Trigger a Source fetch or Tracker refresh from `Operations -> Operations` (below).

See [docs/concepts/integration.md](concepts/integration.md) for the full trigger model.

## Operations

Use `Operations -> Operations` for live background health and one-off operational actions. The
same "Operations" navigation group also holds dedicated pages for Sync Runs, Collection Runs,
Analysis Runs, Queues, and Failed Jobs — each reachable directly from the sidebar rather than only
through this page's stat links.

The page shows:

- Queued job count, failed job count, recent failed jobs.
- Recent sync runs and recent error records.
- Reconciliation and inventory-sync last-run summaries.
- SBOM scan status (recent SbomScan/StaticAnalysis runs).
- Managed schedule entries.

Actions:

- Dispatch one source fetch, or one tracker refresh.
- Reconcile all tracker links (`ReconcileAllJob`, sweeps every alert for missing work-item links).
- Sync inventory (`SyncInventoryJob`, syncs `SoftwareSystem`/`SecurityContainer` rows from every
  registered Source and Source Control provider that supports it).
- Collect repositories (`DispatchRepositoryCollectionRunsJob`, queues an SBOM/vulnerability/secret
  collection sweep across every Azure DevOps repository, run by the isolated `collector`
  container — see [docs/concepts/repository-collection.md](concepts/repository-collection.md)).
- Run static analysis (`DispatchStaticAnalysisRunsJob`, queues a Roslynator/SpotBugs static
  analysis sweep across every Azure DevOps repository, run by the isolated
  `static-analysis-collector` container — see
  [docs/concepts/static-analysis-collection.md](concepts/static-analysis-collection.md)).
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
