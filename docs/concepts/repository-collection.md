# AppSec Scout — Concept: Repository Collection

Repository Collection is the **in-app, asynchronous** counterpart to the host-triggered "Ops"
SbomScan workflow documented in
[docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md). Both produce the same
kind of output — a CycloneDX SBOM plus vulnerability and secret SARIF, per Azure DevOps
repository, ingested into the same Local Finding / Dependency pipeline — but neither supersedes
the other. SbomScan remains a fully valid manual alternative for SBOM/vulnerability/secret
collection. `-StaticAnalysis` (Roslynator/SpotBugs, out of scope here) now also has its own
in-app, asynchronous path — see
[docs/concepts/static-analysis-collection.md](static-analysis-collection.md) — with the identical
"second, parallel path, neither supersedes the other" relationship to its own host-triggered
counterpart. Repository Collection exists so the same kind of sweep can be triggered from
`Operations -> Operations` and run as a queued job, without an operator needing Docker access on their
own workstation.

## Trigger and Access

"Collect repositories" on `Operations -> Operations`, gated by `admin.queue`, dispatches
`App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob`. Like every other action on
that page, syncing is triggered explicitly — there is no scheduler entry, matching the "syncing is
triggered explicitly, never on an automatic schedule" model described in
[docs/concepts/integration.md](integration.md). Unlike every other action on that page, the
per-repository work it dispatches does not run on the app container's own default-queue worker —
see [Isolation: the collector container](#isolation-the-collector-container) below.

## Enumeration

`DispatchRepositoryCollectionRunsJob` enumerates every project and non-disabled repository by
walking the `azdo-repos` Source Control provider's `EnumeratesInventory` methods
(`fetchProjects()`/`fetchRepositories()`) — the same contract
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md) documents
and the same one `InventorySyncService`/`SyncInventoryJob` already use to populate inventory. This
is deliberately scoped to Azure DevOps only: of the three registered Source Control providers,
only `AzDoRepos` and `BitbucketRepos` implement `EnumeratesInventory` today (see that document's
capability matrix), and only AzDO's `ContainerDto` metadata carries a usable Git clone URL —
Bitbucket's does not. Extending this feature to other providers is a separate, future change, not
part of this one.

## Isolation: the `collector` container

`DispatchRepositoryCollectionRunsJob` runs on the app container's own default queue — it only
makes Azure DevOps REST API calls, the same cost profile as `SyncInventoryJob`. For each
repository it finds, it dispatches one `App\SourceControl\Collection\CollectRepositoryJob` as part
of a single `Illuminate\Bus\Batch`, explicitly placed on a dedicated `repository-collection` queue
that the app container's own `queue:work` process never listens to (see `docker/supervisord.conf`
— it runs `queue:work` with no `--queue` flag, so it only consumes the connection's default
queue).

A second, lean Docker image/Compose service, `collector` (`docker/collector/Dockerfile`), runs
`php artisan queue:work --queue=repository-collection` as its only process. It has git and Trivy
but no nginx/php-fpm/build toolchain, and its own scratch volume (`collector_workspace`, mounted
at `/workspace-scratch`) for repository checkouts — a `CollectRepositoryJob` clones into a
per-job-scoped subdirectory of that volume and deletes it once it finishes, whether it succeeded
or failed. `collector` shares `app`'s persisted `.env`/`APP_KEY` (via the `app_storage` volume,
read only for that purpose) so `Credential`-encrypted values like `azdo-repos.pat` decrypt
identically on both sides, and shares the `mysql`/`redis`/`trivy-server` services `app` already
depends on — but never `app_storage`'s application data.

This is a different isolation mechanism from `docker/ops`/`invoke-ops.ps1`: there is no Docker
socket and no container-per-job. Isolation comes entirely from a dedicated queue name consumed by
a dedicated worker image/container — both standard Laravel/Docker Compose primitives, requiring no
custom orchestration code.

v1 does not perform the `.NET` solution restore/build step `collect-sboms.sh` performs before
scanning `.NET` repositories for higher-precision CycloneDX results (see
[SbomScan](sbom-and-static-analysis.md#sbomscan)). Every scan here runs a plain `trivy fs` pass
against the freshly cloned working tree — the same output collect-sboms.sh itself already produces
whenever its own restore/build step fails.

## From Repository to Attachment

`CollectRepositoryJob` clones the repository (via `Illuminate\Support\Facades\Process`, PAT
supplied through a per-job-scoped `.netrc`/`.git-credentials`, never as a process argument or in a
shell string), then runs the same three `trivy fs` invocations against the shared `trivy-server`
container SbomScan uses: SBOM as CycloneDX, vulnerabilities and secrets as SARIF.

Results are written **directly**, in-process — reusing `App\Assets\AttachmentTargetResolver` and
`App\Assets\AzDoScanResultDtoFactory` exactly as `PendingSbomScanImporter` does for
`run.jsonl` lines, then `App\Assets\AttachmentService::attachTo()`. There is no `run.jsonl`, no
file-drop directory, and no per-run cursor file: since `collector` has the same direct database
access `app` does, there is nothing to hand off to a later, separately-scheduled import command.

**Do not confuse the two `source_id` values this feature touches.** `InventorySyncService` upserts
`SoftwareSystem`/`SecurityContainer` rows from the Source Control registry under
`source_id = 'azdo-repos'` (the provider's own `id()`). `CollectRepositoryJob` instead writes
under `source_id = App\Sources\AzDo\AzDoNormalizer::SOURCE_ID` (`'azdo'`) — the same convention
`PendingSbomScanImporter` already uses — so a repository's scan results converge onto the same row
a live AzDO alert sync or `invoke-ops.ps1 -SbomScan` already created or will later create, rather
than creating a second, parallel, unlinked row. `azdo-repos`'s `EnumeratesInventory` methods are
used purely as a **discovery** mechanism here (which projects/repositories exist, and their clone
URL); the AzDO project/repository GUIDs they yield are the same GUIDs `AzDoScanResultDtoFactory`
already keys on.

## From Attachment to Local Finding / Dependency

Every `Attachment` this feature stores flows through the exact same, unmodified pipeline described
in [SbomScan's own section on this](sbom-and-static-analysis.md#from-attachment-to-local-finding--dependency)
— `AttachmentStored` → `ParseAttachmentIntoFindings` → `CycloneDxSbomParser`/`SarifFindingParser`
→ `SoftwareComponent`/`LocalFinding`, plus `PushSbomAttachmentToDependencyTrack` for SBOM
attachments specifically. Nothing in that pipeline changed to support this feature.

## Run Tracking

Each sweep is tracked as an `App\Models\RepositoryCollectionRun` row (`repository_collection_runs`
table) — `source_control_id`, `status` (`running`/`success`/`partial`/`failure`),
`started_at`/`finished_at`, `counts_json`, `error_message` — mirroring `SyncRun`'s shape, plus a
`batch_id` column holding the `Illuminate\Bus\Batch` UUID (`job_batches` table) so the run row and
its underlying batch of `CollectRepositoryJob`s can be cross-referenced for introspection. The
`partial` value was added via a follow-up migration after the original three-value enum proved
insufficient (a run where some but not all repositories failed was otherwise indistinguishable
from a clean success) — the newer `static_analysis_runs` table (see
[docs/concepts/static-analysis-collection.md](static-analysis-collection.md)) started with all
four values from its first migration, learning from this.

Completion is **not** driven by `Illuminate\Bus\Batch`'s own `then()`/`catch()`/`finally()`
callbacks: `job_batches.pending_jobs` was found not to decrement reliably for this workload, so
the run would never leave `running`. Instead, `DispatchRepositoryCollectionRunsJob` seeds
`counts_json.repositories_considered` before dispatching, and each `CollectRepositoryJob` records
its own completion directly against the run row — on success at the end of `handle()`; on failure
only from `failed()`, Laravel's queue hook called exactly once after every retry attempt (`tries`)
is exhausted, never per attempt — under a row lock (`lockForUpdate()`) so concurrent
`collector` workers finishing at the same moment never lose an increment to a race. Once
`repositories_completed` reaches `repositories_considered`, the job that got there last marks the
run `success` and stamps `finished_at`. `allowFailures()` on the batch means one repository's
clone or scan failure never stops the rest of the sweep or the run's own `success` status — it
only shows up as a non-zero `repositories_failed` count in `counts_json`.

Visible on `Operations -> Operations` via a "Recent repository collection runs" widget and a read-only
`RepositoryCollectionRunResource` drill-down (list/view only, no create) — the same pattern
`SyncRunResource`/`RecentSyncRunsTableWidget` already establish for `SyncRun`.

## Related: Ops (SbomScan)

See [docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md). An operator would
still reach for `invoke-ops.ps1 -SbomScan` when running outside this Docker Compose stack, or when
the `.NET` restore/build precision step this feature's v1 does not perform matters for a
particular repository.

## Related: Static Analysis Collection

See [docs/concepts/static-analysis-collection.md](static-analysis-collection.md) — the equivalent
in-app, asynchronous path for Roslynator/SpotBugs static analysis, dispatched independently (its
own `DispatchStaticAnalysisRunsJob`, its own `static-analysis` queue and
`static-analysis-collector` container) but converging its results onto the same
`SoftwareSystem`/`SecurityContainer` rows this feature does, under the same `source_id = 'azdo'`
convention.

## Related: Inventory Sync

See [Related: Inventory Sync](sbom-and-static-analysis.md#related-inventory-sync-assetssync-azdo-projects-appsyncinventorysyncservice)
in the SbomScan document. `InventorySyncService` and this feature both walk `AzDoRepos`'s
`EnumeratesInventory` methods, but write different rows under different `source_id` conventions —
see [From Repository to Attachment](#from-repository-to-attachment) above.

## Related: Dependency-Track

See [Related: Dependency-Track](sbom-and-static-analysis.md#related-dependency-track) in the
SbomScan document — the same `PushSbomAttachmentToDependencyTrack` listener handles SBOM
attachments from either path identically.
