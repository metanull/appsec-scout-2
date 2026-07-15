# AppSec Scout — Concept: Tracker Project Links, Repository Mappings, and Curated Links

Three record types attach on top of the
[Asset/System/Container hierarchy](asset-system-container-alert.md) and, unlike almost
everything else in the app, gate or default specific behavior based purely on **whether they
exist** — not on any permission check. This document explains all three together because they're
easy to conflate (all three are "a link attached to a System/Container") but behave completely
differently: one drives real business logic with a precise fallback chain, one only ever
generates a URL, and one does nothing but render as-is.

| | `TrackerProjectLink` | `RepositoryMapping` | `CuratedLink` |
| --- | --- | --- | --- |
| Can attach to | System, Container (the model still allows Asset, but the Filament relation manager for it was removed — see below) | Asset, System, Container | Asset, System, Container, **and individual Alerts** |
| Precedence when resolving for an Alert | Container → System → tracker-specific global fallback | Container → System | N/A — never resolved, always just rendered |
| Asset-level entries ever consulted for an Alert? | **No** — `SecurityEvent` has no relation to `SoftwareAsset` | **No** — same reason | N/A |
| What it drives | Default tracker/project preselection; reconciliation scope | "View in repo" / "View file" links | Nothing — informational only |
| Created how | Manually, or auto-learned after creating/linking a work item | Manually only | Manually only |

## Tracker Project Link

A `TrackerProjectLink` says "alerts under this System/Container/Asset should be tracked in this
tracker's project by default." Columns: `owner_type`/`owner_id` (polymorphic), `tracker_id`,
`project_key`, `project_name`, `is_default`, `created_by_user_id`, `metadata`.

### Only two levels are exposed in Filament, because only two are ever resolved

The database and model place no restriction on `owner_type` — `SoftwareAsset`, `SoftwareSystem`,
and `SecurityContainer` are all valid owners of a `TrackerProjectLink`. But **an Asset-level link
is never consulted when resolving a default for a specific Alert**, because `SecurityEvent` has no
relation to `SoftwareAsset` at all — only to its System and Container. Because an Asset-level
link could never do anything, `TrackerProjectLinksRelationManager` is registered only on the
System and Container Filament resources, not on the Asset resource — the option is hidden where
it can't work, rather than left as a silent no-op. (`SoftwareAsset::trackerProjectLinks()` still
exists on the model and cascades on delete, so any link created before this was cleaned up is
still there in the database; it's just unreachable from the UI now.) If you're setting up
defaults, put the link on the System or Container that alerts actually belong to.

### Default-project resolution: the exact precedence chain

`App\Trackers\Defaults\TrackerProjectDefaultResolver::resolveForEvent()` runs, in order, for
**every** registered tracker (Jira and GitHub alike):

1. **Container-level link** for this tracker, if the alert has a Container.
2. **System-level link** for this tracker.
3. **Tracker-specific global fallback** — today this step only exists for Jira: the "Jira default
   project key" set on `Admin -> Integrations` (under the Jira row). GitHub has no equivalent
   fallback; if neither Container nor System has a link, GitHub resolution simply comes back
   empty.
4. Otherwise: no default — the operator has to pick a project manually.

**Tie-break within a level**: if exactly one link exists for that tracker at a level, it's used
regardless of its `is_default` flag. If more than one link exists at the same level (e.g. a
Container linked to two different Jira projects for some reason), exactly one of them must be
flagged `is_default`, or that level yields nothing and resolution falls through to the next
level — it does not error, and it does not arbitrarily pick one.

This resolved default is what populates the "Default project: ..." helper text and the
pre-selected Tracker/Project fields in the create-work-item and link-existing-work-item forms
(see [Triage](triage.md#creating-and-linking-tracker-issues)) — for both single-alert and
grouped/bulk flows.

### How links get created: manual, or auto-learned

An operator can create a `TrackerProjectLink` directly from the relation manager on a System or
Container's Filament page. But most links are never typed in by hand — they're
**auto-learned**: every time an operator creates a new work item or links an existing one (single
or grouped), `App\Trackers\TrackerProjectLinker::learnFromEvents()` runs afterward and creates a
link for **every distinct System and every distinct Container** among the alerts just
acted on — both levels simultaneously, in the same transaction as the work-item link itself. This
is idempotent (an existing `(owner, tracker, project)` link just gets its name/metadata
backfilled) and **auto-learned links are never marked `is_default`** — if a level ends up with
more than one candidate link, an operator has to explicitly flag one as default through the
relation manager themselves.

Practical effect: the very first time you create a Jira ticket for an alert under some System,
appsec-scout remembers that System→project pairing for next time, without you configuring
anything up front — but if a second, different project ever gets used for the same System, you
have to break the tie manually or defaults stop resolving at that level.

This auto-learning is one of four independent auto-linking mechanisms in the app; see
[docs/concepts/automated-discovery.md](automated-discovery.md) for how it compares to the other
three (Asset auto-creation, Work Item reconciliation, Local Finding correlation).

### Reconciliation scoping

[Reconciliation](triage.md#reconciliation-the-same-linking-mechanism-two-triggers) uses the exact
same Container/System `TrackerProjectLink` lookup to decide which tracker projects to search for
candidate matches.

`ReconciliationService::reconcileEvent()` itself, if the alert's System/Container has **no**
scoped links at all, silently **widens to every tracker/project pair that has a link anywhere in
the system** — it never returns "nothing to search." The "Find existing work items" button on the
alert detail page runs the same pre-check only to decide whether to show a **warning**
notification ("Searching every configured tracker project instead of a scoped one — results may be
less precise. Add a Tracker Project Link to this alert's system or container to narrow this search
next time."), then unconditionally calls the service either way — a System/Container with no
`TrackerProjectLink` at all does not block reconciliation from the UI, it just searches more
broadly and tells the operator so. `reconcileAll()` (the background, Operations-page-triggered
sweep of every alert) has no scoping concept at all — it always searches every linked project.

## Repository Mapping

A `RepositoryMapping` says "this System/Container corresponds to this repository" — structured
configuration (`repository_provider_id`, `repository_name`, `default_branch`, `path_prefix`) used
to *generate* a URL, never to authenticate or fetch anything. Its resolution precedence for a
given Alert is simpler than `TrackerProjectLink`'s: **Container mapping first (whichever one is
first in the collection — there's no `is_default` tie-break here), falling back to the System
mapping, else nothing.** Same Asset-level blind spot as above, for the same reason (no Asset
relation on `SecurityEvent`).

There is exactly **one consumer** of a resolved Repository Mapping in the whole codebase: the
"Repository" and "Source file" links shown on an alert's detail page (built by
`App\SecurityEvents\EventLinkCatalog`, which calls `RepositoryCodeUrlGenerator` to template the
final URL). Nothing else reads it — in particular, `triage:codesearch` does **not** consult
Repository Mapping at all, despite both being "repository" concepts; codesearch takes an
explicit organization/PAT and a manually-typed scope string instead. See
[Source Control vs. Repository Mapping](sources-trackers-source-control.md#source-control-vs-repository-mapping--two-different-things-that-look-related)
for the fuller distinction between this and an actual Source Control credential.

Repository Mappings are always created manually (via the relation manager) with one exception:
`assets:sync-azdo-projects` and the AzDO fetch cycle auto-create one for every AzDO repository
Container, using the AzDO organization/project as the base URL (see
[Ops](sbom-and-static-analysis.md#related-inventory-sync-assetssync-azdo-projects-appsyncinventorysyncservice)).

## Curated Link

A `CuratedLink` is a free-form bookmark — `label`, arbitrary `url`, a coarse `kind` tag
(source/code/remediation/standard/tracker/other) — added entirely by hand. It is the only one of
the three that can attach directly to an individual `SecurityEvent`, in addition to
Asset/System/Container. **It never gates or defaults anything.** It's rendered verbatim, in
priority order alongside the other link-catalog entries, on the alert detail page and on the
Asset/System/Container navigation pages. There is no query anywhere in the codebase that branches
on a Curated Link's existence, kind, or count to change behavior — searching for programmatic
consumers of `CuratedLink::` outside its own relation manager and service turns up nothing.

## Practical Guidance

- **To make "Create work item" default to a specific Jira/GitHub project for a given
  System or Container**, add a `TrackerProjectLink` there yourself rather than waiting for
  auto-learning — useful before the first ticket is ever created. Prefer the Container level if
  different repositories under the same System really do go to different projects; use the
  System level if they should all share one default.
- **If defaults stop resolving after they used to work**, check whether a second
  `TrackerProjectLink` was added at the same level without one of them being flagged
  `is_default` — that disables resolution at that level rather than erroring, but it's no longer
  silent: the create/link work-item forms now show a warning naming the ambiguous level so it's
  visible before it causes confusion. There is still no database-level constraint preventing more
  than one `is_default` link per level+tracker — the warning is the only safeguard today.
- **"Reconcile work items" ("Find existing work items") always runs**, even with no
  `TrackerProjectLink` on the alert's System or Container — it just widens the search to every
  configured tracker project and warns that results may be less precise. Add a `TrackerProjectLink`
  to narrow the search next time.
- **To make "View in repo"/"View file" links appear on an alert**, add a `RepositoryMapping` at
  the Container level (preferred, since it wins) or the System level — this is unrelated to
  whether a Source Control credential is configured; the link is generated, not fetched.
- **Curated Links are the right tool for anything the other two don't cover** — external
  documentation, a runbook, a related but non-tracker ticketing system, or any URL that doesn't
  need to drive default behavior, since adding one never changes how any other feature works.
