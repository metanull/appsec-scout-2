# AppSec Scout — Concept: Source, Work Tracker, Source Control

This document explains the triad of pluggable roles a security-tooling product can play in
appsec-scout — **Source**, **Work Tracker**, and **Source Control** — what each one is for,
why they stay modeled separately even when one product fills more than one role, and how each
surfaces in the data model and UI. For the scheduling/dispatch mechanics shared by Source and
Tracker (and the corresponding absence of scheduling for Source Control), see
[docs/concepts/integration.md](integration.md).

## Why Three Roles

Sources, Work Trackers, and Source Control providers all follow the same tagged-singleton
registry pattern (bound in `AppServiceProvider`), but they exist to answer three different
questions:

| Role | Question it answers | Registry |
| --- | --- | --- |
| Source | "Where do security alerts come from?" | `App\Sources\Registry` |
| Work Tracker | "Where does remediation work get tracked?" | `App\Trackers\Registry` |
| Source Control | "How does the app authenticate to read repository content?" | `App\SourceControl\Registry` |

A single upstream product can fill more than one role, and when it does, appsec-scout still
models it as two independent integrations with two independent credentials — because the
required access scope genuinely differs. Azure DevOps is the clearest example: its Advanced
Security alert API (Source) and its Code Search / git clone API (Source Control) need different
PAT scopes, so `azdo.pat` (Source) and `azdo-repos.pat` (Source Control) are deliberately
separate vault entries, resolved and rotated independently. The same is true of GitHub: issue
creation (Tracker, `github.token`) and repository clone/push (Source Control,
`github-repos.token`) are separate credentials.

## The Three Contracts

### Source (`App\Sources\Contracts\Source`)

A Source is a system that emits security alerts about software. Its contract is a **pull cycle**
(discover topology, then discover events) plus a narrow, optional **writeback** channel:

| Method | Purpose |
| --- | --- |
| `fetchSystems()` | Enumerate the top-level groupings the source knows about (e.g. an AzDO project) → becomes `SoftwareSystem` rows. |
| `fetchContainers($system)` | For one system, enumerate its sub-groupings (e.g. a repository) → becomes `SecurityContainer` rows. Only meaningful when `SourceCapabilities::hasContainers` is true. |
| `fetchEvents($since, $system)` | The actual alert pull, optionally incremental and optionally scoped → becomes `SecurityEvent` rows. |
| `pushEventState($event)` | Writeback: push a locally staged state/severity/comment change upstream. |
| `fetchRawEvent($event)` / `enrichEvent($event)` | Re-fetch or enrich one event's detail. |

A Source declares zero, one, or none of two optional mix-ins: `EnrichesFetchedEvents` (extra
synchronous detail during the fetch loop) and `QueuesEnrichmentJobs` (defers enrichment to a
queued job).

### Work Tracker (`App\Trackers\Contracts\Tracker`)

A Tracker is a system where remediation work items live. Its contract is richer than Source's,
because it is designed to be written to from day one — project/option discovery plus full CRUD
and reconciliation on work items:

| Method | Purpose |
| --- | --- |
| `fetchProjects()` | Enumerate the tracker's own projects, for the operator to pick from. |
| `fetchItemTypes($projectKey)` / `fetchPriorities($projectKey)` | Populate type/priority dropdowns when creating a work item. |
| `fetchAssigneeCandidates($projectKey, $query)` | Typeahead search for the assignee field. |
| `createWorkItem($request)` | Turn one or more `SecurityEvent`s into a ticket. |
| `getWorkItem($key)` / `updateWorkItem($key, $request)` | Point lookup / partial update of an existing item. |
| `searchWorkItems($projectKey, $query)` | Manual "link an existing item" search. |
| `reconciliationCandidates($projectKey)` | Bulk scan of a project's existing items, for heuristic auto-linking. |

### Source Control (`App\SourceControl\Contracts\SourceControlProvider`)

The base contract is deliberately minimal — four methods, no data-fetching:

```php
interface SourceControlProvider
{
    public function id(): string;
    public function displayName(): string;
    /** @return list<CredentialField> */
    public function credentialFields(): array;
    public function testConnection(): TestResult;
}
```

Its primary purpose is holding a dedicated repo-scoped credential and proving it works. Two of its
consumers (`App\Triage\CodesearchService`, and `invoke-ops.ps1`'s SbomScan/StaticAnalysis/clone-push
flows — see [docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md)) read the
`azdo-repos.*`/`github-repos.*` credential straight out of the vault and build their own HTTP/git
client, without going through the `SourceControlProvider` object.

A provider can additionally implement `App\SourceControl\Contracts\EnumeratesInventory`, an
optional mixin (the same pattern as Source's `EnrichesFetchedEvents`/`QueuesEnrichmentJobs`) with
`fetchProjects(): iterable<SystemDto>` and `fetchRepositories(SystemDto $project):
iterable<ContainerDto>` — reusing the identical DTOs `Source::fetchSystems()`/`fetchContainers()`
return, so both feed the same `SystemContainerUpserter` path. `AzDoRepos` implements it; `GitHubRepos`
does not. See [Populating Inventory From Source Control](#populating-inventory-from-source-control)
below for how this is consumed.

## Capability Matrices

Source and Tracker each expose a capabilities value object that *declares* what a concrete
implementation can do. Source Control has no equivalent value object; its only declarable
capability today is the `EnumeratesInventory` mixin described above.

### `App\Sources\ValueObjects\SourceCapabilities`

| Source | Containers | Update state | Update severity | Add comments | Supported alert types |
| --- | :---: | :---: | :---: | :---: | --- |
| AzDO Advanced Security | Yes | Yes | No | Yes | Vulnerability, Code Quality, Dependency, Secret, License |
| AppScan on Cloud (ASoC) | Yes | Yes | No | Yes | Vulnerability, Dependency, Secret, Misconfiguration |
| Detectify | No | Yes | No | No | Vulnerability, Misconfiguration |

No implemented Source sets `canUpdateSeverity: true` today — severity is not currently pushable
to any connected Source, regardless of how a change is staged in Triage (see
[docs/concepts/triage.md](triage.md)).

Detectify's status-change endpoints (`setfixedstatus`, `setacceptedriskstatus`,
`setfalsepositivestatus`, `unsetfixedstatus`) take no comment/note field — there is no way to
attach a comment to a Detectify status change, so `canAddComments` is `false`.

`canAddComments` means "this Source accepts a comment riding along with a state/severity push" —
it says nothing about pushing a comment added on its own (independent of any state/severity
change). That's a separate, always-`false`-today capability, `canPushStandaloneComment`: no
Source exposes an API for it at all. See
[docs/concepts/upstream-source-capabilities.md](upstream-source-capabilities.md) for the full
per-source, per-operation breakdown, and [docs/concepts/triage.md](triage.md#staging-vs-pushing-a-change)
for how a staged change with no pushable capability gets resolved as a local-only annotation
instead of staying flagged "pending sync" forever.

### `App\Trackers\ValueObjects\TrackerCapabilities`

| Tracker | Labels | Priority | Assignee | Parent link | Item types | Max description size |
| --- | :---: | :---: | :---: | :---: | --- | --- |
| Jira Cloud | Yes | Yes | Yes | Yes | Bug, Task, Story, Epic | 16 KB |
| GitHub Issues | Yes | No | Yes | No | issue | 64 KB |

## What Each Role Populates

| Role | Model | Foreign-key fields back to the integration |
| --- | --- | --- |
| Source | `SoftwareSystem` | `source_id`, `source_system_id` |
| Source | `SecurityContainer` | `source_container_id` (inherits `source_id` transitively via its parent `SoftwareSystem`) |
| Source | `SecurityEvent` | `source_id`, `source_event_id` |
| Work Tracker | `WorkItemLink` | `tracker_id`, `work_item_id` / `work_item_url` |
| Source Control | `SoftwareSystem` (only when implementing `EnumeratesInventory`) | `source_id` (the provider's own id, e.g. `azdo-repos`), `source_system_id` |
| Source Control | `SecurityContainer` (only when implementing `EnumeratesInventory`) | `source_container_id` |

`SecurityEvent` also carries code-location fields (`file_path`, `start_line`, `end_line`,
`snippet`, `commit_sha`, `branch`, `version_control_url`) populated straight from the Source's
raw alert payload — this is descriptive data riding along with the alert, independent of
whether a Source Control credential exists at all.

### Source Control vs. Repository Mapping — two different things that look related

`RepositoryProvider` / `RepositoryMapping` (attached to `SoftwareSystem`/`SecurityContainer` via
a relation manager) is **not** the Source Control concept, even though both involve
"repositories":

- **`RepositoryMapping`** answers "how do I build a clickable URL to this file/repo for a human
  to open in their browser?" It is pure string templating
  (`App\SourceCode\RepositoryCodeUrlGenerator`) — no HTTP call, no credential, ever. It backs the
  "Repository" / "Source file" links shown on an alert's detail page.
- **`SourceControlProvider`** answers "how does the app itself authenticate to clone or query a
  repository programmatically?" It is consumed by `triage:codesearch` and the ops container's
  clone/build/scan flows.

Nothing today auto-derives one from the other — configuring a Repository Mapping doesn't feed a
Source Control credential, and vice versa.

## Dual-Role Products: Separate Credentials, Shared Client Code

| Product | Role 1 | Role 2 | Credential separation | HTTP client |
| --- | --- | --- | --- | --- |
| Azure DevOps | Source (`AzDoSource`, `azdo.pat`/`azdo.organization`) | Source Control (`AzDoRepos`, `azdo-repos.pat`/`azdo-repos.organization`) | Fully separate vault keys | Both construct `App\Sources\AzDo\AzDoClient` — one shared class, two independent instances |
| GitHub | Work Tracker (`GitHubTracker`, `github.token`) | Source Control (`GitHubRepos`, `github-repos.token`) | Fully separate vault keys | Both construct `App\Trackers\GitHub\GitHubClient` — one shared class, two independent instances |

In both cases there is exactly one HTTP client class per product in the whole codebase — a
generic, credential-agnostic Guzzle wrapper. The role-specific classes (`AzDoSource` vs.
`AzDoRepos`, `GitHubTracker` vs. `GitHubRepos`) each construct their own instance of it at
runtime, scoped to their own credential. So: separate credentials and separate live client
instances, but no duplicated HTTP/auth logic.

## Configuration Surfaces

All three registries are exposed identically through the same three Filament pages (see
[docs/concepts/integration.md](integration.md#access-control) for the permission gates on each):

- **`Admin -> System Credentials`** — system-wide secret, for all three kinds.
- **`Profile -> Integrations`** — personal override credential, for all three kinds (yes, an
  individual user can store their own `azdo-repos.pat` or `github-repos.token`, not just
  Source/Tracker credentials).
- **`Admin -> Integrations`** — enable/disable, interval, connection test, for all three kinds'
  registered instances. The interval field renders for Source Control rows too, but is inert —
  nothing reads it, since Source Control has no scheduled job.

Two role-specific scoping mechanisms exist, but neither lives on these three pages — both are
relation managers hung off the `SoftwareSystem`/`SecurityContainer` Filament resources, scoping
the *data model* rather than the integration registry:

- **Tracker Project Links** (`TrackerProjectLinksRelationManager`) — "work items created from
  alerts under this system/container go to tracker project X by default."
- **Repository Mappings** (`RepositoryMappingsRelationManager`) — the link-generation scoping
  described above.

Both have a precise resolution/fallback chain and drive real UI behavior (default project
selection, reconciliation scope, "view in repo" links) purely based on whether a link exists —
see [docs/concepts/links-and-defaults.md](links-and-defaults.md) for the full detail.

A Source has no equivalent scoping step — `fetchSystems()`/`fetchContainers()` are full
enumerations the Source itself decides to expose; `SoftwareSystem`/`SecurityContainer` rows are
an *output* of running the Source, not a pre-configured input. Source Control has no scoping step
either: `triage:codesearch` and the ops container's clone/scan flows consume `enabled` + a
credential, with the caller supplying org/repo/search terms explicitly each time. A provider
implementing `EnumeratesInventory` follows the Source pattern instead — a full, unscoped
enumeration — see below.

### Populating Inventory From Source Control

`App\Sync\InventorySyncService` walks every enabled Source (`fetchSystems()`/`fetchContainers()`)
and every enabled Source Control provider implementing `EnumeratesInventory`
(`fetchProjects()`/`fetchRepositories()`), upserting both through the same
`SystemContainerUpserter`. It backs the `assets:sync-azdo-projects` Artisan command and the
"Sync inventory" action on `Admin -> Operations` (gated by `admin.queue`) — see
[docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md#related-inventory-sync-assetssync-azdo-projects-appsyncinventorysyncservice)
for the full mechanics, including the mark-and-sweep staleness handling
(`App\Assets\StaleRecordSweeper`) that runs after a complete, unfiltered pass.

## Supported vs. Deferred

| Role | Product | Status |
| --- | --- | --- |
| Source | AzDO, ASoC, Detectify | Implemented |
| Source | Defender for Cloud > DevOps | **Deferred** — specified in a planning document (`plan/M5-defender-and-triage-commands.md`) but no runtime code exists |
| Work Tracker | Jira Cloud, GitHub Issues | Implemented |
| Source Control | AzDO Repos, GitHub Repos | Implemented |

## Source Control's Capability Contract

Source Control still has no capabilities value object the way Source and Tracker do — it has one
optional mixin, `EnumeratesInventory` (see above), rather than a declared set of flags. Its base
contract stays lean because its original consumers (`triage:codesearch`, the ops container) each
know exactly what they need and talk to the underlying HTTP/git client directly rather than
through a shared abstraction; `EnumeratesInventory` is additive on top of that, for providers that
also need to feed the System/Container hierarchy.
