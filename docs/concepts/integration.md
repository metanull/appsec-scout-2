# AppSec Scout — Concept: Integration

This document explains "Integration" as a runtime concept: what triggers it, who can see or
control it, and what it is actually used for. It complements [docs/architecture.md](../architecture.md)
(system-wide data flow) and [docs/admin.md](../admin.md) (day-2 operator instructions) with a
focused explanation of this one mechanism.

Related concepts, documented separately:

- [docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md) — what
  each Source/Tracker/Source Control role actually is, independent of the trigger mechanics
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

An Integration is a registered connection to an external system that appsec-scout can sync
on demand. There are three kinds, each following the same tagged-singleton registry pattern
(bound in `AppServiceProvider`):

| Kind | Registry | Members | Role |
| --- | --- | --- | --- |
| Source | `App\Sources\Registry` | AzDO, ASoC, Detectify | Pulls alerts in |
| Tracker | `App\Trackers\Registry` | Jira, GitHub Issues | Pushes/syncs work items out |
| Source Control | `App\SourceControl\Registry` | AzDO Repos, GitHub Repos | Holds a dedicated repo/code credential — see [Source Control](#source-control) |

Each registry exposes `all()` (every registered instance) and `find($id)`; there is no
per-integration "enabled" flag or interval. Syncing is triggered explicitly, never on an
automatic schedule.

## Trigger

Integration jobs are dispatched on demand from `Admin -> Operations`:

- **Fetch source** — dispatches `App\Sync\FetchSourceJob` for one chosen source right now.
- **Refresh tracker** — dispatches `App\Trackers\RefreshWorkItemsJob` for one chosen tracker
  right now.
- **Sync inventory** (`admin.queue`) — dispatches `App\Sync\SyncInventoryJob`, which walks every
  registered Source *and* every registered Source Control provider that implements
  `EnumeratesInventory` to sync `SoftwareSystem`/`SecurityContainer` rows — see
  [docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md#populating-inventory-from-source-control).
  Unlike the other two actions, this one covers Source Control, not just Source/Tracker.

Both `FetchSourceJob` and `RefreshWorkItemsJob` are queued jobs, picked up and executed by a
Supervisor-managed `php artisan queue:work` process on the app's default queue (Redis in normal
operation). Dispatch and execution are two different processes in the same container — a job is
queued immediately but may run a moment later once a worker is free.

Every action taken from the Operations page writes an audit row.

`assets:sync-azdo-projects` is a separate Artisan command that runs the inventory sync for Azure
DevOps from the CLI; it uses the same system-credentialed runtime.

## What Happens When a Job Runs

- **`FetchSourceJob`**: opens a `SyncRun` row (`status = running`), fetches systems, then
  containers, then events since the last successful run, upserts them into `SoftwareSystem` /
  `SecurityContainer` / `SecurityEvent`, and finally marks the `SyncRun` `success` or `failure`.
- **`RefreshWorkItemsJob`**: pulls the latest issue/story state from the tracker and reconciles
  it against local `WorkItemLink` records. Trackers do not get a `SyncRun` history row — only
  Sources do.

Both jobs run under the **system credential** via `App\Sync\SystemIntegrationRuntime`
(`Vault::runAsOwner(null, ...)`) — never the personal token of whoever triggered them.
Operator-credentialed execution (a specific user's own token, used by interactive Triage
actions) goes through `App\Triage\OperatorIntegrationRuntime` instead.

## Access Control

Three distinct UI surfaces control different things, gated by different permissions:

| Page | Controls | Permission | Who has it by default |
| --- | --- | --- | --- |
| `Admin -> System Credentials` | System-wide PATs/tokens shared across all operators | `admin.system-pats` | Admin only |
| `Profile -> Integrations` | Your own personal credential, used only for your own interactive actions | none — just an authenticated session | Every signed-in user, Reader included |
| `Admin -> Operations` "Fetch/Refresh/Sync now" | Force an immediate run | `admin.queue` **or** `work-items.sync` | Admin and Sync |

Roles are cumulative (`Reader < Triage < Plan < Sync < Admin`). Practically, this means:

- Only **Admin** can touch system-wide credentials.
- **Sync**-role users can force a manual run from Operations without being able to see or edit
  the System Credentials page at all.
- Every authenticated user can store their own personal credential on their profile, used only
  for that user's own interactive actions — see the
  [two-flow credential model](../architecture.md#credentials).

## Observability

- **`Admin -> Operations`** shows a "Recent sync runs" widget (gated by `alerts.view`, so visible
  from Reader upward) backed by the `SyncRun` table — the most recent runs across all Sources,
  with status, duration, and record counts. `SyncRun` is the system of record for per-run status.

## Source Control

`App\SourceControl\Registry` (AzDO Repos, GitHub Repos) has no fetch job of its own. Source
Control exists purely to hold a **dedicated credential**
(`azdo-repos.pat`/`azdo-repos.organization`, `github-repos.token`) — kept separate from the
Source's alert-ingestion PAT and the Tracker's issue-tracking token because the required scope
differs (e.g. AzDO's "Code (Read)" vs. its Advanced Security alert scope). That credential is
consumed synchronously, on demand, by whatever needs repository access at the moment —
`triage:codesearch`, AzDO repository auto-linking during inventory sync, and
`invoke-ops.ps1 -SbomScan` / `-StaticAnalysis` / `-Shell` / `-Claude` for cloning or pushing. It
does participate in **Sync inventory** (via `EnumeratesInventory`) to populate
`SoftwareSystem`/`SecurityContainer` rows, but there is nothing to keep "in sync" in the
background.
