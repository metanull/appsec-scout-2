# AppSec Scout — Concept: Ops (SbomScan and StaticAnalysis)

"Ops" here refers to the `ops` Docker container's two organization-wide scan workflows —
**SbomScan** and **StaticAnalysis** — the pipeline that gets their output into appsec-scout as
Local Findings and Dependencies, and a related, simpler inventory tool,
`assets:sync-azdo-projects` (see [below](#related-inventory-only-azdo-sync-assetssync-azdo-projects)).
Don't confuse any of this with the `Admin -> Operations` **Filament page** (covered in
[docs/concepts/integration.md](integration.md)), which is about monitoring and manually
triggering Source/Tracker sync jobs — a completely different thing that happens to share the
word "operations." Nothing in this document runs from Filament.

## Trigger and Access

This is entirely **host-side and operator-initiated** — there is no in-app button, no queued
appsec-scout job, no scheduler entry for starting a scan. An operator with Docker access on
their own machine runs one of:

```powershell
.\scripts\invoke-ops.ps1 -SbomScan -Credential (Get-Credential)
.\scripts\invoke-ops.ps1 -StaticAnalysis -Credential (Get-Credential)
```

`-Credential` can be omitted entirely if the AzDO Repos Source Control credential (see
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md)) is
already configured in appsec-scout's vault — the script fetches `azdo-repos.pat` /
`azdo-repos.organization` from the running `app` container
(`php artisan credentials:system:get ...`) instead of asking the operator to re-enter it. There
is no Filament permission gate on *starting* a scan; access is controlled entirely by who can run
Docker commands against this environment and who holds the AzDO PAT.

Once results land in appsec-scout, they're visible through the normal permission-gated
resources like any other data (`alerts.view` for the containers/findings/components involved) —
there is no special-casing for ops-imported vs. any other data. A read-only
`SbomScanStatusWidget` (gated by `admin.queue`) surfaces recent run status on
`Admin -> Operations`, but has no action buttons.

## SbomScan

Collects a CycloneDX SBOM plus vulnerability and secret findings, per repository, across an
entire Azure DevOps organization:

1. Enumerate every project (filterable by `-ProjectFilter` regex), then every non-disabled
   repository per project (filterable by `-RepositoryFilter` regex).
2. Per repository: shallow-clone it to a temp directory.
3. For every `*.sln` found, `dotnet restore`/`dotnet build` (non-fatal if it fails) — this lets
   Trivy read resolved package versions from `project.assets.json` instead of falling back to
   version ranges declared in `*.csproj`.
4. Run three independent Trivy scans against the shared `trivy-server` container (no local
   vulnerability DB download): CycloneDX SBOM, vulnerability SARIF, secret SARIF — each
   individually toggleable.
5. Delete the cloned repository immediately, then move to the next one.

Trivy authenticates to `trivy-server` with a token shared between them (auto-generated once into
a Docker volume by `trivy-token-init` — no manual setup).

## StaticAnalysis

Same enumerate/clone/delete skeleton as SbomScan, but runs source-code analyzers instead of
Trivy:

- **.NET**: every `*.sln` found anywhere in the repo is restored, built, and analyzed
  individually with Roslynator (`--severity-level info`); each solution's SARIF output is merged
  into one file for the repo.
- **Java**: the repo root is checked for `pom.xml`/`build.gradle[.kts]` first, falling back to a
  shallow recursive search; the project is built with its own `mvnw`/`gradlew` if present
  (otherwise the image's Maven/Gradle), and every directory that ends up with compiled `.class`
  files is analyzed together in one SpotBugs + Find Security Bugs run.

Both ecosystems always run (gated only by which report types are enabled) — a repository
containing both .NET and Java code gets both reports. A failed restore/build for either
ecosystem is non-fatal: analysis is simply skipped for that ecosystem on that repo, and the
failure is captured in a log file alongside the run's other output rather than aborting the scan.

## Output, Resume, and Upload

Both workflows write to a timestamped run directory: `run.jsonl` (one line appended per
repository as it finishes), `summary.json` (aggregate counts), and per-repository report files
plus logs. `-Resume` walks backward through prior run directories to find every repository ID
already attempted in an unbroken chain of interrupted runs, and skips them — a scan interrupted
by a crash can pick up where it left off, even across repeated interrupt/resume cycles.

Getting results **into appsec-scout** is a separate, always-running pipeline, not something the
scan scripts do directly:

- Two scheduled commands, `sbom:import-pending-scans` and `staticanalysis:import-pending-scans`,
  run every minute (`Schedule::command(...)->everyMinute()`) and are also triggered once more
  right after the scan container exits, to flush anything the last scheduled tick missed.
- Each reads every run directory's `run.jsonl` incrementally, tracking a per-run cursor file so
  only newly-appended lines are processed — safe to run every minute against a file that's still
  growing.
- Each report line is matched back to the `SoftwareSystem`/`SecurityContainer` it belongs to (by
  the AzDO project/repository ID recorded when the org was first synced as a Source) and stored
  as an `Attachment`.
- `-SkipUpload` drops a marker file in the run directory that both the scan scripts and these
  import commands recognize, opting that run out of upload entirely — useful for a dry run.

## From Attachment to Local Finding / Dependency

Every `Attachment`, regardless of where it came from, fires an event that a queued listener
(`App\Listeners\ParseAttachmentIntoFindings`) picks up and parses according to its kind:

| Attachment kind | Parser | Resulting model | Matches the user-facing term |
| --- | --- | --- | --- |
| `sbom` | `CycloneDxSbomParser` | `SoftwareComponent` (upserted by package URL) | **Dependencies** |
| `vulnerabilities` / `secrets` / `code-quality-dotnet` / `code-quality-java` | `SarifFindingParser` | `LocalFinding` (upserted by kind + rule + file + line) | **Local Findings** |

The SARIF parser has to work around Trivy encoding package/severity metadata as free-text
`"Key: Value"` lines inside `message.text` (SARIF has no first-class package concept), while
Roslynator/SpotBugs instead rely on the standard SARIF `level` field. After parsing,
`SecurityEventCorrelator` attempts to link each new Local Finding back to an existing Security
Alert, where one already exists for the same underlying issue.

This is the same three-way split described in the app's own data model: a Security Container
holds Security Alerts (from a Source, see
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md)), Local
Findings (from uploaded SARIF), and Dependencies (from uploaded SBOM) — SbomScan/StaticAnalysis
is simply the automated, organization-wide way of producing the SARIF/SBOM files that get
uploaded, instead of an operator uploading them one at a time.

**Local Findings and Dependencies are Alerts' local-only counterpart** — same idea ("something a
scanner found"), but with no external Source behind them, only a file this pipeline uploaded.
That has two consequences worth knowing when relying on repeated SbomScan/StaticAnalysis runs:

- Dependencies (`SoftwareComponent`) are still **read-only** in appsec-scout — no state/severity
  change, no comment, no dismiss action. Local Findings now support a local-only status/severity/
  comment/tracker-linking lifecycle (see [Local Finding and Dependency are Alerts' local-only
  counterpart](asset-system-container-alert.md#local-finding-and-dependency-are-alerts-local-only-counterpart)),
  but re-scanning still never touches the scanner-reported fields themselves, and there is still no
  way to push anything upstream, unlike Alerts — Local Findings and Dependencies have no upstream
  Source to push to.
- **Re-running a scan updates existing rows but never removes ones that disappeared.** A
  vulnerability or secret that's since been fixed and no longer shows up in a later Trivy run
  simply stops being touched by that repository's next `run.jsonl` line — its row stays visible
  indefinitely, with a `last_seen_at` that quietly stops advancing. There is no staleness marker
  and no cleanup job today; a stale finding is not distinguishable in the UI from a current one
  except by comparing `last_seen_at` against how recently the repo was last scanned.

## Related: Inventory-Only AzDO Sync (`assets:sync-azdo-projects`)

A second, much simpler tool also browses an AzDO organization and populates the
[Software Asset / System / Container hierarchy](asset-system-container-alert.md) — but it isn't
a host-side script like SbomScan/StaticAnalysis, and it doesn't touch alerts at all.

```bash
docker compose exec app php artisan assets:sync-azdo-projects --project-filter='^Portal' --repo-filter='.*'
```

It runs *inside* the `app` container (not `ops`), as a plain, manually-invoked Artisan command —
there is no scheduler entry and no dedicated PowerShell wrapper for it (an operator can reach it
through `invoke-app.ps1`'s generic `-Artisan` passthrough, but nothing wraps it specifically).
There is also no permission gate on running it: Artisan commands aren't checked against Spatie
permissions, so the security boundary is simply "who can exec into the `app` container" — the
same trust model as any other console command.

The AzDO PAT it uses to browse projects/repositories follows the same explicit-or-system-credential
resolution as `triage:codesearch` and `invoke-ops.ps1 -SbomScan`/`-StaticAnalysis`: pass `--pat=` to
use a specific token for one run, or omit it to use the `azdo.pat` system credential (the AzDO
Source's own ingestion credential, not the AzDO Repos `azdo-repos.pat` used for code search/clone
access). The underlying `AzDoSource` already fails fast with a clear error if neither is available
or the token is rejected.

It reuses the exact same upsert machinery the normal AzDO Source fetch cycle uses
(`SystemContainerUpserter`, keyed on `source_id`+`source_system_id` for systems and
`software_system_id`+`source_container_id` for containers), so the `SoftwareSystem` rows it
creates or updates are the identical rows a subsequent (or prior) `FetchSourceJob` run for the
`azdo` Source attaches alerts to — there's no separate identity scheme and no duplication risk.
The one thing it deliberately skips is `fetchEvents()` — per its own purpose string, it syncs
"every Azure DevOps project and repository into SoftwareAsset/SoftwareSystem/SecurityContainer/
RepositoryMapping rows, without touching alerts." That makes it useful as a pure inventory
backfill (e.g. populating Assets/Systems/repo mappings ahead of time, or scoped to one project
via `--project-filter`) without waiting for or disturbing the normal alert-sync schedule.

For every AzDO system it touches (whether via this command or the normal fetch cycle),
`App\Assets\AzDoProjectLinker` auto-creates a dedicated `SoftwareAsset` (one per AzDO project) the
first time that system is seen, unless it's already linked to an Asset — manual or automatic
assignment is never overwritten. This is the only automatic cross-run grouping behavior in the
codebase today; see
[docs/concepts/asset-system-container-alert.md](asset-system-container-alert.md) for how
`SoftwareAsset` grouping works in general.

## Related: Dependency-Track

Dependency-Track is a separate, independent application (own Postgres database) used purely to
visualize dependencies — not part of the SbomScan/StaticAnalysis pipeline itself, but wired to
receive its output automatically. Whenever an `Attachment` of kind `sbom` is stored for a
Security Container, a queued listener
(`App\Listeners\PushSbomAttachmentToDependencyTrack`) uploads it to Dependency-Track's API,
provided a Dependency-Track API key is configured. A separate manual command,
`sbom:export-dependency-track`, re-pushes every container's latest stored SBOM as a full resync,
independent of the per-attachment listener.
