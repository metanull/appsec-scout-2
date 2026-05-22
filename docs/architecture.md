# AppSec Scout — Architecture

This document describes the implemented Laravel architecture through M6.

## High-Level Flow

```mermaid
flowchart LR
    subgraph Sources
        AZDO[AzDO]
        ASOC[ASoC]
        DET[Detectify]
    end

    subgraph Scheduler
        DISP[integrations:dispatch-due]
    end

    subgraph Queue
        FETCH[FetchSourceJob]
        TRACK[RefreshWorkItemsJob]
        PUSH[PushEventStatesJob]
        OPS[Operational jobs]
    end

    subgraph AppDB[MySQL]
        SYS[software_systems]
        CONT[security_containers]
        EVT[security_events]
        LINKS[work_item_links]
        RUNS[sync_runs]
        ISET[integration_settings]
        CREDS[credentials]
        AUD[audit_logs]
        ERR[error_logs]
        FAIL[failed_jobs]
    end

    subgraph Trackers
        JIRA[Jira]
        GH[GitHub Issues]
    end

    subgraph UI[Filament UI]
        DASH[Dashboard]
        ALERTS[Reader and triage resources]
        PLAN[Planning actions]
        SYNC[Pending Sync]
        ADMIN[Admin pages]
    end

    AZDO --> FETCH
    ASOC --> FETCH
    DET --> FETCH

    DISP --> FETCH
    DISP --> TRACK
    ADMIN --> DISP

    FETCH --> SYS
    FETCH --> CONT
    FETCH --> EVT
    FETCH --> RUNS
    FETCH --> AUD

    TRACK --> LINKS
    TRACK --> AUD
    TRACK --> ISET

    ALERTS --> EVT
    ALERTS --> CONT
    ALERTS --> SYS
    PLAN --> LINKS
    SYNC --> PUSH
    ADMIN --> ISET
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

    CREDS --> FETCH
    CREDS --> TRACK
    CREDS --> PLAN
    CREDS --> PUSH
    OPS --> ERR
    OPS --> AUD
```

## Runtime Topology

The local and production-style runtime is a single `app` image plus MySQL and Redis in Compose.

Inside the `app` container, Supervisor runs:

- nginx
- php-fpm
- `php artisan schedule:work`
- `php artisan queue:work`

The app container is hardened to run as `www-data` with:

- read-only root filesystem
- all Linux capabilities dropped
- writable storage volume and tmpfs runtime paths

## Data Ownership

AppSec Scout is the system of record for operator edits.

- source fetch jobs read upstream systems into local tables
- triage and planning actions update only the local database
- Sync role actions are the only flows that write alert state or comments back to upstream sources
- tracker refresh updates local work-item metadata only

## Credentials

Credential storage is centralized in the `credentials` table.

Resolution model:

1. explicit preferred user
2. current authenticated user
3. integration service user
4. system credential

This supports both interactive user actions and scheduled background jobs.

## Deferred Scope

Defender for Cloud is intentionally deferred from M6 and is not represented in the supported runtime paths documented here.