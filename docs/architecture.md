# AppSec Scout — Architecture

This document describes the implemented Laravel architecture: the runtime topology, the data
flow between upstream integrations and the local database, and the credential model that ties
them together.

## High-Level Flow

```mermaid
flowchart LR
    subgraph Sources
        AZDO[AzDO]
        ASOC[ASoC]
        DET[Detectify]
    end

    subgraph SourceControl[Source Control]
        AZDOREPOS[AzDO Repos]
        GHREPOS[GitHub Repos]
    end

    subgraph Queue
        FETCH[FetchSourceJob]
        TRACK[RefreshWorkItemsJob]
        PUSH[PushEventStatesJob]
        RECON[ReconcileAllJob]
        SYNCINV[SyncInventoryJob]
        SBOMIMP[sbom / staticanalysis import]
        DTPUSH[Dependency-Track upload]
        DISPATCHCOLLECT[DispatchRepositoryCollectionRunsJob]
        DISPATCHANALYZE[DispatchStaticAnalysisRunsJob]
    end

    subgraph Collector[Collector container - queue-driven, isolated]
        COLLECTJOB[CollectRepositoryJob]
    end

    subgraph StaticAnalysisCollector[Static analysis collector container - queue-driven, isolated]
        ANALYZEJOB[AnalyzeRepositoryJob]
    end

    subgraph AppDB[MySQL / PostgreSQL]
        SYS[software_systems]
        CONT[security_containers]
        EVT[security_events]
        LF[local_findings]
        SC[software_components]
        LINKS[work_item_links]
        RUNS[sync_runs]
        RUNS2[repository_collection_runs]
        RUNS3[static_analysis_runs]
        CREDS[credentials]
        AUD[audit_logs]
        ERR[error_logs]
        FAIL[failed_jobs]
    end

    subgraph Trackers
        JIRA[Jira]
        GH[GitHub Issues]
    end

    subgraph Ops[Ops container - host-triggered]
        SBOMSCAN[SbomScan / StaticAnalysis]
    end

    subgraph DTStack[Dependency-Track + Trivy]
        DT[Dependency-Track]
        TRIVY[Trivy server]
    end

    subgraph UI[Filament UI]
        DASH[Dashboard]
        ALERTS[Reader and triage resources]
        PLAN[Planning actions]
        SYNC[Pending Sync]
        ADMIN[Admin pages]
        OPSPAGE[Operations page]
    end

    AZDO --> FETCH
    ASOC --> FETCH
    DET --> FETCH
    AZDOREPOS --> SYNCINV
    AZDOREPOS --> DISPATCHCOLLECT
    AZDOREPOS --> DISPATCHANALYZE
    GHREPOS -.->|no EnumeratesInventory yet| SYNCINV

    OPSPAGE --> FETCH
    OPSPAGE --> TRACK
    OPSPAGE --> SYNCINV
    OPSPAGE --> RECON
    OPSPAGE --> DISPATCHCOLLECT
    OPSPAGE --> DISPATCHANALYZE

    FETCH --> SYS
    FETCH --> CONT
    FETCH --> EVT
    FETCH --> RUNS
    FETCH --> AUD
    SYNCINV --> SYS
    SYNCINV --> CONT

    TRACK --> LINKS
    TRACK --> AUD
    RECON --> LINKS

    ALERTS --> EVT
    ALERTS --> CONT
    ALERTS --> SYS
    ALERTS --> LF
    ALERTS --> SC
    PLAN --> LINKS
    SYNC --> PUSH
    ADMIN --> CREDS
    ADMIN --> FAIL
    ADMIN --> RUNS
    ADMIN --> AUD
    ADMIN --> ERR

    PLAN --> JIRA
    PLAN --> GH
    TRACK --> JIRA
    TRACK --> GH

    PUSH --> AZDO
    PUSH --> ASOC
    PUSH --> DET
    PUSH --> RUNS
    PUSH --> AUD
    PUSH --> ERR

    SBOMSCAN -->|SARIF / CycloneDX, run.jsonl| SBOMIMP
    SBOMIMP --> LF
    SBOMIMP --> SC
    SBOMSCAN -->|Trivy scans| TRIVY
    SC --> DTPUSH
    DTPUSH --> DT

    DISPATCHCOLLECT --> RUNS2
    DISPATCHCOLLECT -->|repository-collection queue| COLLECTJOB
    COLLECTJOB -->|Trivy scans| TRIVY
    COLLECTJOB --> LF
    COLLECTJOB --> SC
    COLLECTJOB --> RUNS2

    DISPATCHANALYZE --> RUNS3
    DISPATCHANALYZE -->|static-analysis queue| ANALYZEJOB
    ANALYZEJOB --> LF
    ANALYZEJOB --> RUNS3

    CREDS --> FETCH
    CREDS --> TRACK
    CREDS --> PLAN
    CREDS --> PUSH
    CREDS --> SYNCINV
    CREDS --> COLLECTJOB
    CREDS --> ANALYZEJOB
```

## Runtime Topology

The default Compose stack (`docker-compose.yml`, no profile needed) starts these services:

| Service | Image | Role |
| --- | --- | --- |
| `app` | `appsec-scout:latest` | Laravel app: nginx + php-fpm + scheduler + queue worker, run under Supervisor |
| `mysql` | `mysql:8.0` | Primary database (default engine) |
| `postgres` | `postgres:16-alpine` | Optional primary database replacing `mysql` when the `docker-compose.pgsql.yml` override is activated via `COMPOSE_FILE` in the root `.env` |
| `redis` | `redis:7-alpine` | Cache, queue, and session backend |
| `dependencytrack-postgres` | `postgres:16-alpine` | Dependency-Track's own database |
| `dependencytrack-cacerts-init` | `dependencytrack/apiserver` | One-shot: merges any corporate CA into a shared truststore volume for the API server |
| `dependencytrack-apiserver` | `dependencytrack/apiserver` | Dependency-Track API |
| `dependencytrack-frontend` | `dependencytrack/frontend` | Dependency-Track UI |
| `trivy-token-init` | `appsec-scout:latest` | One-shot: generates the shared token `trivy-server` and `dependencytrack-bootstrap` use to authenticate to each other |
| `trivy-server` | `aquasec/trivy:latest` | Self-hosted vulnerability database server, used by Dependency-Track's Trivy analyzer and by the SbomScan/StaticAnalysis workflows (see [docs/concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md)) |
| `dependencytrack-bootstrap` | `appsec-scout:latest` | One-shot: provisions a Dependency-Track team, API key, and Trivy analyzer config, storing the API key in the credential vault |
| `collector` | `appsec-scout-collector:latest` | Isolated worker for in-app repository collection: git + Trivy, consumes only the `repository-collection` queue — see [docs/concepts/repository-collection.md](concepts/repository-collection.md) |
| `static-analysis-collector` | `appsec-scout-static-analysis-collector:latest` | Isolated worker for in-app static analysis: git + .NET/Roslynator + Java/Maven/Gradle/SpotBugs+FindSecBugs + Opengrep, consumes only the `static-analysis` queue — see [docs/concepts/static-analysis-collection.md](concepts/static-analysis-collection.md) |

`node` (profile `tools`) and `ops` (profile `ops`) are opt-in and not started by a plain
`docker compose up` — see [docs/operations.md](operations.md) for when to use them. `collector`
and `static-analysis-collector`, unlike `ops`, have no profile and start by default alongside
`app` — they back in-app features, not a manual operator tool.

Inside the `app` container, Supervisor runs `nginx`, `php-fpm`, `php artisan schedule:work`, and
`php artisan queue:work` (see `docker/supervisord.conf` for the exact flags).

The `app` and `dependencytrack-bootstrap` services run as `www-data` with a read-only root
filesystem, all Linux capabilities dropped, and writable storage volumes plus tmpfs-backed
runtime paths.

## Data Ownership

AppSec Scout is the system of record for operator edits.

- Source fetch jobs and inventory syncs read upstream systems/repositories into local tables.
- Triage and planning actions update only the local database.
- Sync-role actions are the only flows that write alert state, severity, or comments back to a
  Source.
- Tracker refresh updates local work-item metadata only.
- SbomScan/StaticAnalysis and their import commands are the equivalent read path for Local
  Findings and Dependencies — see [docs/concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md).
- Repository collection (`Operations -> Operations` "Collect repositories") is a second, in-app,
  asynchronous read path for the same kind of data, scoped to Azure DevOps repositories — see
  [docs/concepts/repository-collection.md](concepts/repository-collection.md).
- Static analysis collection (`Operations -> Operations` "Run static analysis") is the equivalent
  second, in-app, asynchronous read path for Roslynator/SpotBugs/Opengrep code-quality findings —
  see [docs/concepts/static-analysis-collection.md](concepts/static-analysis-collection.md).

## Credentials

Credential storage is centralized in the `credentials` table, encrypted at rest.

There are exactly two credential-resolution flows:

- **System-triggered operations** (scheduled sync, background jobs, bulk Ops-page actions such as
  "Reconcile all tracker links") resolve the system credential (`owner_user_id IS NULL`, set via
  `Admin -> System Credentials`, `Vault::runAsOwner(null, ...)`).
- **User-triggered interactive actions** (creating/linking a work item, the per-alert "Find
  existing work items" action) resolve that specific user's own personal credential (set via
  `Profile -> Integrations`, `Vault::runAsOwner($operatorUserId, ...)`).

Which flow applies is fixed by the kind of operation. A missing required credential fails with a
clear error.

## Related Documents

- [docs/concepts/integration.md](concepts/integration.md) — the scheduling/dispatch mechanics for
  Source and Tracker sync.
- [docs/concepts/sources-trackers-source-control.md](concepts/sources-trackers-source-control.md) —
  what each Source/Tracker/Source Control role is, independent of scheduling.
- [docs/concepts/asset-system-container-alert.md](concepts/asset-system-container-alert.md) — the
  entity hierarchy this architecture populates.
- [docs/concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md) — the
  host-triggered SBOM/static-analysis pipeline and the Dependency-Track integration.
- [docs/concepts/repository-collection.md](concepts/repository-collection.md) — the in-app,
  queued, isolated-container SBOM/vulnerability/secret collection path for Azure DevOps
  repositories.
- [docs/concepts/static-analysis-collection.md](concepts/static-analysis-collection.md) — the
  in-app, queued, isolated-container Roslynator/SpotBugs/Opengrep static analysis path for Azure
  DevOps repositories.
- [docs/install.md](install.md), [docs/operations.md](operations.md), [docs/security.md](security.md)
  — install, day-2 operations, and security posture.

## Out of Scope

Defender for Cloud > DevOps is specified as a planned Source but has no runtime code — see
[docs/concepts/sources-trackers-source-control.md](concepts/sources-trackers-source-control.md#supported-vs-deferred).
