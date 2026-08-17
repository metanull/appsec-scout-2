# AppSec Scout — Concept: Static Analysis Collection

Static Analysis Collection is the **in-app, asynchronous** counterpart to two other, related
paths: the host-triggered "Ops" `StaticAnalysis` workflow documented in
[docs/concepts/sbom-and-static-analysis.md](sbom-and-static-analysis.md#staticanalysis), and
[docs/concepts/repository-collection.md](repository-collection.md) — the equivalent in-app path
for SBOM/vulnerability/secret collection. None of the three supersedes another. `StaticAnalysis`
remains a fully valid manual alternative (in particular for `-Resume`/`-ProjectFilter`/
`-RepositoryFilter`/`-SkipUpload`, none of which this in-app path supports). Repository Collection
covers different Attachment kinds (`sbom`/`vulnerabilities`/`secrets`, via Trivy) than this feature
does (`code-quality-dotnet`/`code-quality-java`, via Roslynator/SpotBugs); the two are dispatched,
queued, and run entirely independently of each other, even though both converge their results onto
the same `SoftwareSystem`/`SecurityContainer` rows for a given repository.

## Trigger and Access

"Run static analysis" on `Admin -> Operations`, gated by `admin.queue`, dispatches
`App\SourceControl\Collection\DispatchStaticAnalysisRunsJob`. Like every other action on that
page, it is triggered explicitly — there is no scheduler entry, matching the "syncing is triggered
explicitly, never on an automatic schedule" model described in
[docs/concepts/integration.md](integration.md). Unlike most other actions on that page, the
per-repository work it dispatches does not run on the app container's own default-queue worker —
see [Isolation: the static-analysis-collector container](#isolation-the-static-analysis-collector-container)
below.

## Enumeration

`DispatchStaticAnalysisRunsJob` enumerates every project and non-disabled repository by walking
the `azdo-repos` Source Control provider's `EnumeratesInventory` methods
(`fetchProjects()`/`fetchRepositories()`) — the identical mechanism
[Repository Collection's own Enumeration section](repository-collection.md#enumeration) documents,
including the same Azure-DevOps-only scoping and the same reasons for it. The enumeration code
itself (`buildTargets()`) is duplicated rather than shared between the two dispatch jobs — a
deliberate choice, consistent with `AnalyzeRepositoryJob` duplicating rather than sharing
`CollectRepositoryJob`'s own clone mechanics.

## Isolation: the `static-analysis-collector` container

`DispatchStaticAnalysisRunsJob` runs on the app container's own default queue — it only makes
Azure DevOps REST API calls, the same cost profile as `SyncInventoryJob`/
`DispatchRepositoryCollectionRunsJob`. For each repository it finds, it dispatches one
`App\SourceControl\Collection\AnalyzeRepositoryJob` as part of a single `Illuminate\Bus\Batch`,
explicitly placed on a dedicated `static-analysis` queue — separate from Repository Collection's
own `repository-collection` queue, and from the app container's own default queue.

A second, dedicated Docker image/Compose service, `static-analysis-collector`
(`docker/static-analysis-collector/Dockerfile`), runs `php artisan queue:work
--queue=static-analysis` as its only process. Unlike `collector` (git + Trivy only), this image
carries the full .NET/Java build+analysis toolchain: .NET 10 SDK, Roslynator, Eclipse Temurin JDK,
Maven, Gradle, SpotBugs + Find Security Bugs — copied from `docker/ops/Dockerfile`'s own pinned
versions, minus that image's interactive-shell-only layers (GitHub CLI, Claude Code, BFG Repo
Cleaner, global Pest/PHPStan/Pint) and Trivy, which this container never calls. It has its own
scratch volume (`static_analysis_collector_workspace`, mounted at `/workspace-scratch`), separate
from `collector`'s own `collector_workspace` — the two containers' disk usage is never shared or
conflated, even though neither ever collides today. `static-analysis-collector` shares `app`'s
persisted `.env`/`APP_KEY` (via the `app_storage` volume, read only for that purpose) and the
`mysql`/`redis` services `app` already depends on, but — unlike `collector` — has no dependency on
`trivy-server`.

A **separate, dedicated queue and container** exists here rather than reusing `collector`/
`repository-collection` because the two workloads have fundamentally different timeout profiles: a
Trivy filesystem scan is three fixed-timeout operations per repository, while static analysis is
restore (up to 600s) + build (up to 900s) + analyze (up to 900s) per `.sln`, potentially repeated
across several solutions, plus an independent Java build+analyze pass — a compile-then-analyze job
that can run for tens of minutes per repository must never share a worker or queue with the fast,
predictable Trivy scans, in either direction.

`App\Queue\QueueRuntimeInspector` explicitly accounts for the `static-analysis` queue in the
Operations page's "Queued jobs" stat (a `STATIC_ANALYSIS_QUEUE` constant unioned into its queue
list, mirroring the equivalent `REPOSITORY_COLLECTION_QUEUE` handling) — without this, a pending
static analysis sweep would be invisible on that page, since `static-analysis` never appears in
`queue.connections.*.queue` (only the app container's own worker's configured queues do). See
[docs/operations.md](../operations.md) for what the Operations page shows.

This is the same isolation mechanism Repository Collection uses, not `docker/ops`/
`invoke-ops.ps1`'s: no Docker socket, no container-per-job — isolation comes entirely from a
dedicated queue name consumed by a dedicated worker image/container, both standard Laravel/Docker
Compose primitives.

## From Repository to Attachment

`AnalyzeRepositoryJob` clones the repository (identical mechanics to `CollectRepositoryJob`'s own
clone step: `Illuminate\Support\Facades\Process`, PAT supplied through a per-job-scoped
`.netrc`/`.git-credentials`, never as a process argument or in a shell string), then runs the same
two-ecosystem analysis `docker/ops/collect-static-analysis.sh` runs today:

- **`.NET`**: every `*.sln` found anywhere in the clone is restored, then — regardless of the
  build's own result — built and analyzed with Roslynator (`--severity-level info`). Every
  solution that produces a non-empty SARIF file has its `runs` merged into a single
  `code-quality-dotnet` Attachment.
- **Java**: the topmost `pom.xml`/`build.gradle[.kts]` directory (the repo root first, else a
  shallow recursive search) is built independently and non-fatally with its own `mvnw`/`gradlew`
  wrapper if present, else the image's Maven/Gradle. Afterward, every directory anywhere in the
  clone with compiled `.class` files — independent of which directory actually built them — is
  analyzed together in one SpotBugs + Find Security Bugs run, producing a `code-quality-java`
  Attachment.

A clone failure fails the repository, exactly as it does for `CollectRepositoryJob`. A
restore/build/analyze failure for one solution or project directory is logged (`ErrorLog`,
channel `static-analysis`) and non-fatal — a Java-only repository failing its nonexistent `.NET`
build is not a failure, matching `collect-static-analysis.sh`'s own per-ecosystem independence.

Results are written **directly**, in-process — reusing `App\Assets\AttachmentTargetResolver` and
`App\Assets\AzDoScanResultDtoFactory` exactly as `CollectRepositoryJob`/`PendingSbomScanImporter`
do, then `App\Assets\AttachmentService::attachTo()`.

**Do not confuse the two `source_id` values this feature touches** — the identical gotcha
[Repository Collection's own equivalent section](repository-collection.md#from-repository-to-attachment)
describes. `InventorySyncService` upserts `SoftwareSystem`/`SecurityContainer` rows under
`source_id = 'azdo-repos'`; `AnalyzeRepositoryJob` instead writes under
`source_id = App\Sources\AzDo\AzDoNormalizer::SOURCE_ID` (`'azdo'`) — the same convention every
other AzDO-origin writer in this codebase uses — so a repository's static analysis results
converge onto the same row a live AzDO alert sync, `-SbomScan`, `-StaticAnalysis`, or
`CollectRepositoryJob` already created or will later create, rather than creating a second,
parallel, unlinked row.

## From Attachment to Local Finding / Dependency

Every `Attachment` this feature stores flows through the exact same, unmodified pipeline described
in [SbomScan's own section on this](sbom-and-static-analysis.md#from-attachment-to-local-finding--dependency)
— `AttachmentStored` → `ParseAttachmentIntoFindings` → `SarifFindingParser` →
`LocalFinding` (kind `code_quality`). Nothing in that pipeline changed to support this feature;
`SarifFindingParser` already distinguished Roslynator/SpotBugs SARIF from Trivy's before this
epic existed.

## Run Tracking

Each sweep is tracked as an `App\Models\StaticAnalysisRun` row (`static_analysis_runs` table) —
`source_control_id`, `status` (`running`/`success`/`partial`/`failure`),
`started_at`/`finished_at`, `counts_json`, `error_message`, `batch_id` — structurally identical to
`RepositoryCollectionRun`. Unlike that table, `static_analysis_runs` shipped with all four
`status` values from its first migration: `repository_collection_runs` only gained `partial` via a
follow-up migration once its original three-value enum proved insufficient (a run where some but
not all repositories failed was otherwise indistinguishable from a clean success) — this table
started from that corrected shape instead of repeating the same gap.

Completion follows the identical row-locked, per-repository self-reporting mechanism
[Repository Collection's own Run Tracking section](repository-collection.md#run-tracking)
describes in detail (not `Illuminate\Bus\Batch`'s own callbacks, found unreliable for this kind of
workload). `allowFailures()` on the batch means one repository's clone or analysis failure never
stops the rest of the sweep or the run's own eventual `success`/`partial` status — it only shows
up as a non-zero `repositories_failed` count in `counts_json`.

Visible on `Admin -> Operations` via a "Recent static analysis runs" widget and a read-only
`StaticAnalysisRunResource` drill-down (list/view only, no create), including a Force-finish
action for a run wedged at `running` (e.g. a `static-analysis-collector` job died mid-flight) —
the same pattern `RepositoryCollectionRunResource` established, itself modeled on
`FailedJobResource`'s retry/forget pattern.

## Related: Ops (StaticAnalysis)

See [docs/concepts/sbom-and-static-analysis.md#staticanalysis](sbom-and-static-analysis.md#staticanalysis).
An operator would still reach for `invoke-ops.ps1 -StaticAnalysis` when running outside this Docker
Compose stack, or when needing `-Resume`, `-ProjectFilter`/`-RepositoryFilter`, or `-SkipUpload` —
none of which this in-app path supports.

## Related: Repository Collection

See [docs/concepts/repository-collection.md](repository-collection.md). The two features are
dispatched independently, run on independent queues (`static-analysis` vs. `repository-collection`)
and containers (`static-analysis-collector` vs. `collector`), but converge their results onto the
same `SecurityContainer` rows for a given repository under the identical `source_id = 'azdo'`
convention — proven directly by a dedicated test in
`tests/Feature/SourceControl/Collection/StaticAnalysisPipelineTest.php`.

## Related: Inventory Sync

See [Related: Inventory Sync](sbom-and-static-analysis.md#related-inventory-sync-assetssync-azdo-projects-appsyncinventorysyncservice)
in the SbomScan document. `InventorySyncService` and this feature both walk `AzDoRepos`'s
`EnumeratesInventory` methods, but write different rows under different `source_id` conventions —
see [From Repository to Attachment](#from-repository-to-attachment) above.
