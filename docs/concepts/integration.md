# AppSec Scout — Concept: Integration

This document explains "Integration" as a runtime concept: what triggers it, who can see or
control it, and what it is actually used for. It complements [docs/architecture.md](../architecture.md)
(system-wide data flow) and [docs/admin.md](../admin.md) (day-2 operator instructions) with a
focused explanation of this one mechanism.

Related concepts, documented separately:

- [docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md) — what
  each Source/Tracker/Source Control role actually is, independent of the scheduling mechanics
  covered here.
- [docs/concepts/triage.md](triage.md) — the manual, user-driven workflow that produces the
  staged changes a Source sync eventually pushes upstream.
- [docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md) — the separate,
  host-side "Ops" scan workflows (not to be confused with the `Admin -> Operations` page
  described below).
- [docs/concepts/asset-system-container-alert.md](asset-system-container-alert.md) — the entity
  hierarchy (Software Asset / System / Container / Alert) that everything above ultimately reads
  from and writes to.
- [docs/concepts/links-and-defaults.md](links-and-defaults.md) — how Tracker Project Links,
  Repository Mappings, and Curated Links attach to that hierarchy and drive default/gating
  behavior in Triage.
- [docs/concepts/automated-discovery.md](automated-discovery.md) — the four independent
  mechanisms that automatically create or set a relationship on a best-effort basis, and the
  shared philosophy behind them.

## What "Integration" Means

An Integration is a registered connection to an external system that appsec-scout keeps in sync
on a recurring, automated basis. There are three kinds, each following the same tagged-singleton
registry pattern (bound in `AppServiceProvider`), but only two of them actually run on a schedule:

| Kind | Registry | Members | Scheduled? |
| --- | --- | --- | --- |
| Source | `App\Sources\Registry` | AzDO, ASoC, Detectify | Yes — pulls alerts in |
| Tracker | `App\Trackers\Registry` | Jira, GitHub Issues | Yes — pushes/syncs work items out |
| Source Control | `App\SourceControl\Registry` | AzDO Repos, GitHub Repos | **No** — see [Source Control Is Not Scheduled](#source-control-is-not-scheduled) |

Every registered instance gets one row in the `integration_settings` table, keyed by
`(integration_kind, integration_id)` — for example `source:azdo`, `tracker:jira`,
`source_control:azdo-repos`. Each row holds `enabled`, `fetch_interval_minutes`,
`service_user_id`, and the outcome of its last run (`last_synced_at`, `last_sync_status`,
`last_sync_message`).

## Trigger

There are two ways an integration's job gets dispatched — both end up running the exact same
job classes.

### Scheduled (automatic)

```
Schedule::command('integrations:dispatch-due')->everyMinute()->withoutOverlapping()
```

This runs every minute inside the `app` container, driven by the Supervisor-managed
`php artisan schedule:work` process. Each tick, `App\Integrations\DispatchDueIntegrations`
loops every registered Source and Tracker and asks
`IntegrationSettingsRepository::isDue($kind, $id)`:

- **due** = `enabled = true` AND (`last_synced_at` is null OR
  `now() - last_synced_at >= fetch_interval_minutes`)

Due sources get `FetchSourceJob::dispatch($id)`; due trackers get
`RefreshWorkItemsJob::dispatch($id)`. Both are queued jobs, picked up and executed by a
*separate* Supervisor-managed process, `php artisan queue:work`, on the app's default queue
(Redis in normal operation). Dispatch and execution are two different processes in the same
container — a due integration is queued immediately but may run a moment later once a worker
is free.

Changing `enabled` or `fetch_interval_minutes` from `Admin -> Integrations` takes effect on the
very next minutely tick — no scheduler restart is needed.

### Manual (on-demand)

`Admin -> Operations` exposes several actions that bypass the "is it due" check entirely:

- **Dispatch due integrations** — runs the exact same `DispatchDueIntegrations` logic
  immediately, instead of waiting for the next tick.
- **Fetch source** — dispatches `FetchSourceJob` for one chosen source right now, regardless of
  its interval.
- **Refresh tracker** — dispatches `RefreshWorkItemsJob` for one chosen tracker right now.
- **Sync inventory** (`admin.queue`) — dispatches `App\Sync\SyncInventoryJob`, which walks every
  enabled Source *and* every enabled Source Control provider that implements `EnumeratesInventory`
  to sync `SoftwareSystem`/`SecurityContainer` rows — see
  [docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md#populating-inventory-from-source-control).
  Unlike the other three actions, this one covers Source Control, not just Source/Tracker.

Every action taken from the Operations page writes an audit row.

## What Happens When a Job Runs

- **`FetchSourceJob`**: opens a `SyncRun` row (`status = running`), fetches systems, then
  containers, then events since the last successful run, upserts them into `SoftwareSystem` /
  `SecurityContainer` / `SecurityEvent`, and finally marks both the `SyncRun` and the
  `integration_settings` row `success` or `failure`.
- **`RefreshWorkItemsJob`**: pulls the latest issue/story state from the tracker and reconciles
  it against local `WorkItemLink` records, then updates the same `integration_settings` status
  fields. Trackers do not get a `SyncRun` history row — only Sources do.

Both jobs run under the **system credential** (`Vault::runAsOwner(null, ...)`) — never the
personal token of whoever triggered them, whether that trigger was the scheduler or an Admin
clicking a button on the Operations page.

## Access Control

Three distinct UI surfaces control different things, gated by different permissions:

| Page | Controls | Permission | Who has it by default |
| --- | --- | --- | --- |
| `Admin -> Integrations` | Enable/disable, interval, service user, connection test | `admin.integrations` | Admin only |
| `Admin -> System Credentials` | System-wide PATs/tokens shared across all operators | `admin.system-pats` | Admin only |
| `Profile -> Integrations` | Your own personal credential (used as a fallback/override) | none — just an authenticated session | Every signed-in user, Reader included |
| `Admin -> Operations` "Dispatch/Fetch/Refresh now" | Force an immediate run | `admin.queue` **or** `work-items.sync` | Admin and Sync |

Roles are cumulative (`Reader < Triage < Plan < Sync < Admin`). Practically, this means:

- Only **Admin** can decide *whether or how often* something syncs, or touch system-wide
  credentials.
- **Sync**-role users can force a manual run from Operations without being able to see or edit
  the Integrations or System Credentials pages at all.
- Every authenticated user can store their own personal credential on their profile, which the
  [credential resolution order](../architecture.md#credentials) prefers over the system
  credential for interactive actions taken as that user.

## Observability

- **`Admin -> Operations`** shows a "Recent sync runs" widget (gated by `alerts.view`, so visible
  from Reader upward) backed by the `SyncRun` table — the 10 most recent runs across all
  Sources, with status, duration, and record counts.
- The Integrations table's "Last sync status" badge blends three signals, in priority order: an
  actively `running` `SyncRun` → **in progress**; a job already sitting in the queue but not yet
  started, detected by `QueueRuntimeInspector` reading the live queue payloads → **queued**;
  otherwise the persisted `last_sync_status` from the previous run.

## Source Control Is Not Scheduled

`App\SourceControl\Registry` (AzDO Repos, GitHub Repos) deliberately does not participate in
any of the above. `DispatchDueIntegrations` only knows about `SourceRegistry` and
`TrackerRegistry` — there is no fetch job for Source Control, no due/interval evaluation, and
`last_synced_at` is never set for `azdo-repos` or `github-repos`. Their `enabled` toggle and
interval field still appear in `Admin -> Integrations` because the page renders every kind
generically, but nothing currently reads those two fields for Source Control rows.

This is intentional, not an oversight: Source Control exists purely to hold a **dedicated
credential** (`azdo-repos.pat`/`azdo-repos.organization`, `github-repos.token`) — kept separate
from the Source's alert-ingestion PAT and the Tracker's issue-tracking token because the
required scope differs (e.g. AzDO's "Code (Read)" vs. its Advanced Security alert scope). That
credential is consumed synchronously, on demand, by whatever needs repository access at the
moment — `triage:codesearch`, and `invoke-ops.ps1 -SbomScan` / `-StaticAnalysis` / `-Shell` /
`-Claude` for cloning or pushing. There is nothing to keep "in sync" in the background, so there
is no scheduled job.
