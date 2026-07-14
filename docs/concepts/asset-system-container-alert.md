# AppSec Scout — Concept: Software Asset / System / Container / Alert

This document explains the core entity hierarchy appsec-scout uses to organize everything it
collects: **Software Asset > Software System > Security Container > (Security Event / Local
Finding / Software Component)**. It complements
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md) (where
Systems/Containers/Events come from), [docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md)
(where Local Findings and Dependencies come from), and
[docs/concepts/links-and-defaults.md](links-and-defaults.md) (how Tracker Project Links,
Repository Mappings, and Curated Links attach to this hierarchy and drive default/gating
behavior elsewhere in the app).

## Known Documentation Drift

Before the model itself: `CLAUDE.md` previously listed `SoftwareSystemLink`, `SecurityContainerLink`,
and `InferenceSuggestion` as implemented Filament resources. **None of the three exist in this
codebase** — no model, no migration, no Filament resource, nothing. That documentation has been
corrected. Cross-source grouping today works entirely through the plain mechanism described
below (a nullable foreign key plus manual attach/detach, and one deterministic AzDO-specific
auto-linker) — there is no link-table, no confidence-scored suggestion queue, and no
accept/reject workflow anywhere in the code.

## The Hierarchy and Its Cardinalities

```
SoftwareAsset  (0 or 1) ──┐
                          ▼
                  SoftwareSystem  (exactly 1, mandatory)
                          │
                          ▼
                SecurityContainer  (0 or 1 — optional layer)
                          │
              ┌───────────┼──────────────────┐
              ▼           ▼                  ▼
       SecurityEvent  LocalFinding   SoftwareComponent
        ("Alert")   ("Local Finding")  ("Dependency")
```

| Relationship | Cardinality | Enforced by |
| --- | --- | --- |
| Asset → System | 1 : 0..N (a System belongs to zero or one Asset) | `software_systems.software_asset_id`, nullable FK, `nullOnDelete()` |
| System → Container | 1 : 0..N, **mandatory child→parent** | `security_containers.software_system_id`, non-nullable FK |
| System → Event | 1 : N, **mandatory** — every alert always has a System | `security_events.software_system_id`, non-nullable, `cascadeOnDelete()` |
| Container → Event | 1 : 0..N, **optional** | `security_events.container_id`, nullable, `nullOnDelete()` |
| System/Container/Asset → LocalFinding, SoftwareComponent | dual-attached: polymorphic `owner` **and** a denormalized direct FK to whichever of System/Asset applies | see below |

The chain is tree-shaped at each individual step, but not a strict tree end-to-end: a System can
have zero Assets (ungrouped), and an Event/Finding/Component can skip the Container layer
entirely and attach directly to its System. **The System is the true mandatory anchor for
everything beneath it** — a Container is an optional intermediate grouping *within* a System, not
a required one.

This directly explains why Detectify alerts have no Container: `SourceCapabilities::hasContainers`
is `false` for Detectify (it has no repository-like sub-grouping to report), so
`ContainerDto.sourceContainerId` is never populated for its events, and `container_id` simply
stays null on those `SecurityEvent` rows — they attach straight to the System.

## Software Asset

A `SoftwareAsset` is a manually-curated grouping of one or more `SoftwareSystem` rows that
represent "the same real project" as seen through different Sources — the canonical example
from the app's own domain description: a project that exists both as an AzDO repository (where
the code lives) and an ASoC application (where DAST runs) gets one Asset grouping both.

Columns: `name`, `description`, `metadata` (json). It has no fields of its own beyond a label and
description — all its substance comes from the Systems (and, transitively, the Containers,
Alerts, Local Findings, and Dependencies) attached to it.

**Grouping mechanism**: a plain nullable `software_asset_id` column on `software_systems` —
*not* a pivot/join table. Attaching or detaching a System is nothing more than reassigning that
one foreign key (`App\Assets\SoftwareAssetService::attach()`/`detach()`), each recorded as an
audit entry. Deleting an Asset does not delete its Systems — the FK is `nullOnDelete()`, so
Systems are simply orphaned back to unassigned rather than removed.

**Where this happens**:
- **Manually**, in Filament: `Admin`/`Plan` users with `context.curate` create an Asset (name +
  description only — you don't pick Systems at creation time), then use the "Link software
  system" action on its System relation manager, which only offers currently-unassigned Systems.
- **Automatically, for AzDO only**: `App\Assets\AzDoProjectLinker` creates a dedicated Asset the
  first time an AzDO-sourced System is seen (one Asset per AzDO project), unless that System is
  already linked to an Asset — manual or prior automatic assignment is never overwritten. This
  runs as part of both the normal AzDO fetch cycle and the standalone
  `assets:sync-azdo-projects` command (see
  [docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md#related-inventory-only-azdo-sync-assetssync-azdo-projects)).
  No other Source has an equivalent auto-linker — an ASoC or Detectify System is never
  automatically grouped into an Asset; that's always a manual step.

There is no cross-Source *matching* logic anywhere (e.g. nothing tries to guess that an AzDO
project named "Portal" and an ASoC application named "Portal" are the same thing) — reconciling
Systems from different Sources into one Asset is always either a human decision, or, for AzDO
specifically, the deterministic "one Asset per AzDO project" default described above. This is one
of four independent auto-linking mechanisms in the app; see
[docs/concepts/automated-discovery.md](automated-discovery.md) for how it compares to the other
three.

## Software System

A `SoftwareSystem` is one row per **(Source, remote-system) pair** — its natural key is
`source_id` + `source_system_id`. This means the same real-world project reported by two
different Sources always produces **two distinct System rows**, unified only by both being
attached to the same Asset — there is no single "canonical system" row that both Sources write
into.

Columns of note: `software_asset_id` (nullable FK, described above), `source_id`,
`source_system_id`, `name`, `description`, `url`, `metadata` (json — see
[SourceContextFacts](#a-note-on-context) below), `first_seen_at`, `last_seen_at`, `synced_at`.

A System is created and kept up to date by whichever Source produced it (see
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md) for the
`fetchSystems()` contract) — it is an *output* of running a Source, never a pre-configured input.

## Security Container

A `SecurityContainer` is the optional sub-grouping within a System — typically a repository
within an AzDO project or ASoC application. Natural key: `software_system_id` +
`source_container_id`. Only Sources with `SourceCapabilities::hasContainers = true` (AzDO, ASoC)
produce these; Detectify never does.

A Container is a mandatory child of exactly one System (non-nullable FK) — the database enforces
`restrictOnDelete()` on that FK, but the `SoftwareSystem` model's `deleting` hook explicitly
cascades (`$system->containers->each->delete()`) before the parent row is removed, so a normal
Eloquent delete still works; only bypassing Eloquent entirely (raw SQL) would hit the DB-level
restriction.

## Security Event ("Alert"), Local Finding, and Software Component ("Dependency")

These are the three kinds of record that actually attach to the Container/System layer. They
look similar — all three describe "something found" — but only one of them has an external
Source behind it:

| Model | User-facing term | Populated from | Has an upstream Source? |
| --- | --- | --- | --- |
| `SecurityEvent` | Alert | A Source's `fetchEvents()` (see [sources-trackers-source-control.md](sources-trackers-source-control.md)) | Yes |
| `LocalFinding` | Local Finding | Uploaded/parsed SARIF (see [sbom-and-static-analysis.md](sbom-and-static-analysis.md)) | **No — 100% local** |
| `SoftwareComponent` | Dependency | Uploaded/parsed SBOM/CycloneDX (see [sbom-and-static-analysis.md](sbom-and-static-analysis.md)) | **No — 100% local** |

`SecurityEvent` attaches via a mandatory `software_system_id` and an optional `container_id`
(described above). `LocalFinding` and `SoftwareComponent` attach through a **polymorphic
`owner`** (any of Asset/System/Container) *plus* a denormalized direct nullable FK to whichever
of `software_system_id`/`software_asset_id` applies — added in a later migration specifically so
"show me every Dependency/Local Finding under this System" doesn't require walking the polymorphic
relation for common queries.

### Local Finding and Dependency are Alert's local-only counterpart

A Local Finding or Dependency is conceptually the same kind of thing as an Alert — "something a
scanner found" — with one structural difference that shapes everything else about it: **there is
no external Source behind it**, only a file an operator (or the [Ops SbomScan/StaticAnalysis
workflow](sbom-and-static-analysis.md)) uploaded. That has real consequences:

- **No writeback, because there's nothing to write back to.** `Source::pushEventState()` (the
  mechanism [Triage](triage.md) uses to push a staged Alert change upstream) is implemented only
  for `SecurityEvent`. There is no equivalent for `LocalFinding`/`SoftwareComponent` — not because
  it's unimplemented, but because there's no upstream system on the other end to push to.
- **No triage lifecycle at all.** Unlike `SecurityEvent`, neither model has `state`,
  `pending_state`, `pending_severity`, or `is_dirty` columns. `LocalFindingResource` and
  `SoftwareComponentResource` are genuinely **read-only** in Filament: their `form()` is empty, no
  `create`/`edit` page is registered, and there is no delete action anywhere — no
  `StateChanger`/`SeverityChanger`/`CommentManager` equivalent exists for either model. Both are
  gated by the same `alerts.view` permission as Alerts, with no separate view/edit/delete
  permission of their own.
- **No tracker linking.** `WorkItemLink` has exactly one subject column, `event_id` — a Local
  Finding or Dependency cannot be linked to a Jira/GitHub issue the way an Alert can. If a Local
  Finding needs a tracker ticket, that has to happen through the Alert it's correlated to (below),
  or through a Curated Link pointing at wherever it's tracked externally.
- **Re-scanning updates, but never removes.** Re-uploading a fresh SARIF/SBOM for the same
  owner upserts on a natural key (`(owner, kind, rule_id, file_path, start_line)` for Local
  Finding; a true unique `(owner, purl)` constraint for Dependency), bumping `last_seen_at`. But
  nothing diffs against the previous scan to notice a finding *disappeared* — there is no
  staleness flag, no archival job, and no cleanup. A vulnerability that's since been fixed and no
  longer appears in Trivy's output simply stops being touched; its row lingers indefinitely,
  still fully visible, with an increasingly stale `last_seen_at` and no signal that it's actually
  gone.

### Local Finding fields and severity

Beyond the owner/hierarchy columns: `kind` (`vulnerability` / `secret` / `code_quality`),
`rule_id`, `title`, `description`, `severity`, `file_path`, `start_line`/`end_line`,
`package_name`/`package_version` (Trivy vulnerability findings only), `metadata` (raw SARIF
result), `correlated_security_event_id`, `correlation_method`, `first_seen_at`/`last_seen_at`.

Severity is derived per-finding, not per-kind: the parser first looks for a Trivy-style
`Severity: ...` line inside the SARIF `message.text` free text (SARIF has no first-class severity
field of its own beyond `level`), and only falls back to mapping the standard SARIF `level`
(`error`/`warning`/`note` → `HIGH`/`MEDIUM`/`LOW`) when no such line exists — which is exactly the
case for Roslynator/SpotBugs findings, since they don't encode a Trivy-style severity line.

### Correlation: linking a Local Finding back to an Alert

`App\Assets\SecurityEventCorrelator` makes a best-effort, conservative attempt to recognize when
a locally-scanned finding is actually the same underlying issue as an Alert already synced from a
live Source — "leaves it unset (never guesses) otherwise," per its own doc comment. Two
heuristics, scoped to the finding's own Asset/System/Container:

- **Vulnerability findings** — matched against `SecurityEvent`s of type `Dependency` by exact
  package name (case-insensitive) and version.
- **Secret findings** — matched against `SecurityEvent`s of type `Secret` with the identical
  `file_path`, within 2 lines of the reported line number.
- **Code-quality findings are never correlated** — there's no Alert-side equivalent to match
  against.

A successful match sets `correlated_security_event_id` and `correlation_method` — this is a
visible, queryable link (`LocalFindingResource` shows a "Correlated alert" column linking straight
to the Alert), not a silent internal computation. **`SoftwareComponent` is never correlated** —
the correlator's API only ever accepts a `LocalFinding`. Unlike the other three auto-linking
mechanisms in the app (see [docs/concepts/automated-discovery.md](automated-discovery.md)), there
is no way to correct a wrong correlation through the UI — `LocalFindingResource` is read-only.

## Curated Links, Repository Mappings, and Tracker Project Links

Three more record types attach to this hierarchy, each shaping specific behavior elsewhere in the
app (default project selection, "view in repo" links, informational bookmarks). They're covered
together, in depth, in
[docs/concepts/links-and-defaults.md](links-and-defaults.md) — briefly:

- **`CuratedLink`** — a free-form, human-typed bookmark. Never gates or defaults anything;
  purely rendered as-is. Uniquely among the three, it can attach directly to an individual
  `SecurityEvent`, not just Asset/System/Container.
- **`RepositoryMapping`** — structured configuration consumed programmatically to generate
  browse-to-file URLs for an Alert's code location. See
  [sources-trackers-source-control.md](sources-trackers-source-control.md#source-control-vs-repository-mapping--two-different-things-that-look-related)
  for how this differs from an actual Source Control credential.
- **`TrackerProjectLink`** — maps a System or Container to a tracker project, and is what drives
  default project selection and reconciliation scoping when [creating or linking a tracker
  issue](triage.md#creating-and-linking-tracker-issues).

## Permission Summary

| Action | Permission | Who has it |
| --- | --- | --- |
| View Asset/System/Container/Alert/Finding/Component (`alerts.view`) | `alerts.view` | Reader and above |
| Create/edit/delete a `SoftwareAsset`; attach/detach a System; add a Curated Link | `context.curate` | Plan, Sync, Admin |
| Manage a Repository Provider (`admin.repository-providers`) | `admin.repository-providers` | Plan, Sync, Admin |
| Edit or delete a `LocalFinding`/`SoftwareComponent` | *(none — no such action exists)* | Nobody; both are read-only |

`assets:sync-azdo-projects` and the AzDO auto-linker inside the normal fetch cycle bypass all of
this — they write directly via Eloquent from a trusted, unauthenticated context (a console
command or a queued job), the same trust model as any other scheduled/CLI operation in the app.

## A Note on Context

Two differently-named, differently-purposed things both deal with "context" and are easy to
confuse:

- **`App\Sources\Context\SourceContextFacts`** — a static registry of dotted-key constants
  (`AZDO_PROJECT_ID`, `CODE_DEFAULT_BRANCH`, `PACKAGE_NAME`, `SECURITY_CVE`, and others) used to
  read/write nested values inside the `metadata` JSON column that every hierarchy entity carries,
  regardless of which Source produced it. It's a normalized vocabulary for source-specific
  metadata, not a model or a service.
- **`App\Context\Quality\ContextQualityService`** — an unrelated, UI-facing "is this record fully
  curated" checker. Given an Alert/System/Container, it returns badge-style indicators (missing
  repository mapping, missing tracker mapping, missing source URL) shown on Filament view pages.
  It doesn't store anything or generate suggestions — it's a read-only completeness nudge.
