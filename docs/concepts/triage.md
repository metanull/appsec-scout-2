# AppSec Scout — Concept: Triage

Triage is the user-driven workflow for working an alert once it has landed locally: finding it,
changing its state or severity, commenting on it, and creating or linking a remediation ticket.
Unlike [Source/Tracker/Source Control syncing](integration.md), Triage has no scheduler at all —
every action here is a person clicking something in the Filament UI.

## Core Principle: Local-First

Every Triage action writes to appsec-scout's own database first and only there. AppSec Scout is
fully usable in a **standalone mode** — download alerts, triage them, create tracker issues —
without ever pushing anything back upstream. Pushing a state/severity change back to the
originating Source is a separate, optionally-used capability, gated by a different permission
than Triage itself, and not every Source even supports it (see
[Staging vs. Pushing a Change](#staging-vs-pushing-a-change) below).

## Trigger and Surfaces

There is no scheduled job for any Triage action. Everything is initiated from
`App\Filament\Resources\SecurityEventResource`, which has two distinct pages:

- **List** (`ListSecurityEvents`) — the filterable/searchable/sortable alert table, with row and
  bulk actions.
- **Detail** (`ViewSecurityEvent`) — one alert's full infolist, with relation-manager tabs:
  Comments, Curated Links, Work Item Links, Attachments, Audit History.

## Filtering, Sorting, Searching

Filters available on the list page: severity (multi), state (multi), source (multi), software
system (searchable), security container (searchable), alert type (multi), tags, "has work item"
(yes/no), "pending sync" (`is_dirty`, yes/no), and a compound tracker + work-item-ID filter.

Search matches `title`, `description`, `rule_id`, and the raw `metadata` payload
(`LIKE`-based, portable across MySQL/SQLite). Sortable columns include severity (ranked
critical → informational, not alphabetical), state, source, type, title, and first/last seen
timestamps; the default sort (when nothing else is chosen) is severity rank descending, then
most-recently-seen first.

A user's last filter/search/sort selection is persisted per-user and restored on their next
visit to the list page — there is no separate "saved views"/tabs feature, just this one
remembered state per user.

## Staging vs. Pushing a Change

**Staging (Triage role, `alerts.edit`)**: `App\Triage\StateChanger` and
`App\Triage\SeverityChanger` never write directly to the `state`/`severity` columns. Instead
they set `pending_state`/`pending_severity`, require and store a `pending_comment` (minimum 10
characters), and flip `is_dirty = true`. `StateChanger::changeMany()` is the bulk equivalent,
available as a table bulk action (`alerts.bulk-edit`). Both record an audit entry.

**Pushing (Sync role, `work-items.sync` *and* `sources.push-state`)**: staged changes sit on
`Admin -> Pending Sync` (`App\Filament\Pages\PendingSyncPage`) until someone with both
permissions reviews and pushes them. The page groups dirty events by Source and its one action,
`pushToSource`, dispatches one queued `App\Sync\PushEventStatesJob` per Source group, which calls
`Source::pushEventState($event)`. On success it clears `pending_state`/`pending_comment` and
sets `state = pending_state`.

Behavior worth knowing when reading this flow — `App\Sync\PendingSyncResolver` (shared by
`PushEventStatesJob` and the one-off `events:recompute-pending-sync` backfill command) decides,
for every dirty event handed to a sync run, whether it actually has something pushable for its
Source:

- A staged **state** change (`pending_state`) is pushable whenever the Source declares
  `canUpdateState: true` (every shipped Source does): `PushEventStatesJob` calls
  `Source::pushEventState($event)`, and on success clears `pending_state`/`pending_comment` and
  sets `state = pending_state`.
- A pending **severity** change is pushable only when the Source declares `canUpdateSeverity: true`
  (see the capability matrix in
  [docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md#capability-matrices)
  and the full per-source detail in
  [docs/concepts/upstream-source-capabilities.md](upstream-source-capabilities.md)) — resolved the
  normal way when it is (attempt the push, clear on success). When it isn't, rather than leaving
  the event flagged "pending sync" forever, the sync run resolves it as **local-only**: it leaves a
  system-authored note on the alert's own Comments tab explaining why (visible right where an
  operator would look, not as a warning at edit time), logs an `ErrorLog` warning, and clears
  `is_dirty` — but `pending_severity` itself is left untouched, so the staged value stays visible
  as a durable local annotation. Because of this, the alert page's "Pending Sync" section stays
  visible whenever `pending_severity` is set, even after `is_dirty` clears — not just while
  genuinely awaiting a push.
- A standalone **comment** (staged by itself, not alongside a state/severity change) gets the
  identical local-only treatment — no Source can push a comment independent of a state/severity
  change (see [docs/concepts/upstream-source-capabilities.md](upstream-source-capabilities.md)),
  so it's resolved the same way: a system note, an `ErrorLog` warning, `is_dirty` cleared.
- `SourceCapabilities` isn't consulted by the Triage UI at edit time — "Change severity" and "Add
  comment" are available identically regardless of Source, gated purely by `alerts.edit`, by
  design: the local-first model always allows a local edit; the "this can't push anywhere" fact
  is only surfaced later, when a sync run actually resolves it, not as a warning while editing.

## Commenting

`App\Triage\CommentManager::add()` creates an `EventComment` row and marks the parent event
`is_dirty`. Comments distinguish origin: `upstream_comment_id` is set for a comment that was
synced down from the Source, left null for a comment authored locally. A user can edit their own
local comment (`canEdit()`) only within 5 minutes of creation — comments synced from upstream can
never be edited locally.

## Creating and Linking Tracker Issues

`App\Trackers\WorkItemService` powers both flows, identically whether triggered for one alert
(row/detail-page action) or many at once (bulk action on the list page):

- **`createForEvents()`** — builds a title/description (a grouped description gets a severity
  table and per-type sections when more than one alert is selected), calls
  `Tracker::createWorkItem()`, then creates one `WorkItemLink` row **per alert**, all pointing at
  the same `work_item_id` — this is how "one ticket for a group of alerts" is represented: N link
  rows sharing a tracker + work-item-id pair. Gated by `work-items.create`.
- **`linkExisting()`** — looks up an already-existing tracker issue and links it the same way,
  without creating anything upstream. Gated by `work-items.link`.

Both flows check that the acting user has a usable personal tracker credential first (see
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md)); if not,
they're redirected to `Profile -> Integrations` instead of proceeding.

Both forms also try to pre-select a tracker and project. That default — and, separately, how
narrowly scoped "Find existing work items" (below) searches — comes from a `TrackerProjectLink`
attached to the alert's System or Container, with its own precise Container-then-System fallback
chain and an auto-learning mechanism that records one after every create/link action. See
[docs/concepts/links-and-defaults.md](links-and-defaults.md) for the full resolution logic.

### Reconciliation: the same linking mechanism, two triggers

Reconciliation heuristically finds tracker issues that already reference an alert (by matching
its URL against text mined from candidate issues) and links them automatically, without an
operator manually searching:

- **Per-alert, on demand** — the alert detail page's "Find existing work items" action runs
  `ReconciliationService::reconcileEvent()` synchronously, scoped to the tracker projects linked
  to that alert's system/container when one exists. Gated by `work-items.link` or
  `work-items.sync`. This is a Triage action. With no `TrackerProjectLink` on the alert's System or
  Container, the button still runs — it shows a warning notification ("Searching every configured
  tracker project instead of a scoped one — results may be less precise.") and falls back to
  searching every configured tracker project, exactly matching what the service itself does when
  invoked without a scope (see
  [docs/concepts/links-and-defaults.md](links-and-defaults.md#reconciliation-scoping)).
- **Whole-database, in the background** — `Admin -> Operations`'s "Reconcile all tracker links"
  action queues `ReconcileAllJob` across every alert. Gated by `admin.queue`/`work-items.sync`.
  This is an Ops-page action, not a Triage one, even though it uses the identical underlying
  matching logic and creates the same kind of `WorkItemLink` row.

Reconciliation is one of four independent auto-linking mechanisms in the app; see
[docs/concepts/automated-discovery.md](automated-discovery.md) for how it compares to the other
three (Asset auto-creation, Tracker Project Link auto-learning, Local Finding correlation).

## Codesearch

`triage:codesearch` is a manual **Artisan command**, not a Filament button — there is no
"search code" action anywhere in the Triage UI today. An operator runs it directly
(`php artisan triage:codesearch {search} --pat= --scope= --attach-to=`), optionally attaching the
JSON result to an alert as an Attachment (visible on that alert's Attachments tab, "Created by"
shown as `triage:codesearch` since there's no interactive user attached to a CLI run). The PAT is
resolved the same way `invoke-ops.ps1 -SbomScan`/`-StaticAnalysis` resolve theirs: `--pat` is used
if given, otherwise the command falls back to the `azdo-repos.pat` system credential; if neither is
available the command fails fast with a clear error instead of attempting the search. Running the
command is gated only by having a shell on the `app` container — there is no in-app permission for
it, since Artisan commands aren't checked against Spatie permissions anywhere in the app.
`App\Triage\RunCodesearchJob` (a queued wrapper for the same logic) exists but is only exercised in
tests today; nothing in production dispatches it.

## Permission Matrix

Roles are cumulative: `Reader ⊂ Triage ⊂ Plan ⊂ Sync ⊂ Admin`.

| Permission | Reader | Triage | Plan | Sync | Admin |
| --- | :---: | :---: | :---: | :---: | :---: |
| `alerts.view` (see alerts, list/detail pages) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `alerts.edit` (change state/severity, comment) | | ✓ | ✓ | ✓ | ✓ |
| `alerts.bulk-edit` (bulk state change) | | ✓ | ✓ | ✓ | ✓ |
| `work-items.create` (create tickets, single/grouped) | | | ✓ | ✓ | ✓ |
| `work-items.link` (link existing tickets, reconcile) | | | ✓ | ✓ | ✓ |
| `work-items.sync` (push staged changes; also gates reconcile-all) | | | | ✓ | ✓ |
| `sources.push-state` (push staged changes) | | | | ✓ | ✓ |

Practically: Reader can only look. Triage can stage state/severity changes and comment, but
cannot create tickets or push anything upstream. Plan adds ticket creation/linking. Sync adds
the ability to push staged changes back to a Source — `Admin -> Pending Sync` requires **both**
`work-items.sync` and `sources.push-state`, so Triage-role users can never push a change
themselves even though they're the ones who staged it.
