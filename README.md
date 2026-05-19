# AppSec Scout

Ground-up rewrite in **PHP 8.4 / Laravel 13 / Filament 5 / MySQL**, packaged as a containerized Linux application.

## Purpose

AppSec Scout aggregates application-security alerts from multiple upstream sources — **AppScan on Cloud (ASoC)**, **Azure DevOps Advanced Security (AzDO)**, **Detectify**, and **Microsoft Defender for Cloud > DevOps** — into a single local database. Operators triage alerts, propagate state changes back upstream, and create remediation work items in **Jira** or **GitHub Issues**, all from one interface.

## Background

Previous iterations were built in Node.js (TUI, per-source) and then .NET (CLI + WinUI 3, then Avalonia). Each delivered value but faced deployment friction on corporate machines due to security and network policies. This rewrite starts fresh, consolidates all lessons learned, and targets a **Docker-first deployment model** that works under corporate constraints (HTTP proxy, restricted installs) without compromise.

## Stack

| Layer         | Choice                                                |
| ------------- | ----------------------------------------------------- |
| Language      | PHP 8.4                                               |
| Framework     | Laravel 13                                            |
| UI            | Filament 5 (single panel at `/`, not admin-only)      |
| Database      | MySQL 8.0+                                            |
| Queue / Cache | Redis                                                 |
| Auth          | Laravel Fortify — email/password + mandatory TOTP 2FA |
| Authorization | `spatie/laravel-permission`                           |
| Testing       | Pest (parallel)                                       |
| Linting       | Laravel Pint                                          |

## Operating Model

- Background jobs fetch alerts from upstream sources on a configurable schedule.
- Operators edit **only the local database** — upstream sources are never touched except when a Sync operator explicitly propagates changes.
- All write actions are recorded with timestamp and actor identity in an audit log.
- Personal Access Tokens (PATs) for upstream sources, Jira, and GitHub are stored per-user in the application; actions are always performed under the operator's own identity.

## Roles

| Role       | Permissions                                                       |
| ---------- | ----------------------------------------------------------------- |
| **Reader** | View all alerts and related data                                  |
| **Triage** | Reader + edit alert state, add comments                           |
| **Sync**   | Reader + propagate local changes back to upstream sources         |
| **Plan**   | Reader + link alerts to work items, create Jira/GitHub stories    |
| **Admin**  | Manage users, integrations, queues, system PATs, audit/error logs |

Roles are combinable (Reader / Triage / Sync / Plan may be stacked). Admin cannot be combined with operational roles but implicitly includes Reader.

## Architecture & Deployment

The application is designed from day one as a **containerized Linux application**:

- Single Docker image bundling PHP-FPM, Nginx, Supervisor, and all triage binaries (Trivy, BFG, JRE, git).
- `docker compose up` starts the application, MySQL, and Redis.
- HTTP proxy configured once via environment variables (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`) and honored by every outbound HTTP client.
- VS Code runs inside the container during development.

## Milestones

| #   | Milestone                                                                       | Scope                                                                                                                                                     |
| --- | ------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1  | [Foundation](plan/M1-foundation.md)                                             | Laravel + Filament scaffold, Docker image, MySQL, HTTP proxy, audit log, error log, authentication, 5 roles                                               |
| M2  | [Sources read-only + Reader UI](plan/M2-sources-and-reader.md)                  | Domain model, source plugin contract, AzDO / ASoC / Detectify sources, sync orchestrator, Reader UI, composite software systems                           |
| M3  | [Triage + Sync roles](plan/M3-triage-and-sync.md)                               | Local state edits, comments, bulk triage, pending-sync review page, upstream propagation job                                                              |
| M4  | [Plan role + Jira/GitHub trackers](plan/M4-plan-and-trackers.md)                | Tracker plugin contract, description builder, Jira tracker, GitHub tracker, work-item creation (single + grouped), tracker refresh, credential management |
| M5  | [Defender for Cloud + Triage commands](plan/M5-defender-and-triage-commands.md) | Defender source (Service Principal auth), `triage:trivy`, `triage:bfg`, `triage:codesearch` Artisan commands, event attachments                           |
| M6  | [Admin polish + packaging](plan/M6-admin-polish.md)                             | Queue/schedule UI, integration management, user management + 2FA reset, image hardening, end-to-end Pest suite, operator documentation                    |

See [plan/README.md](plan/README.md) for the full decision log and epic/story breakdown.

## Development Rules

- **Fail-fast** — errors are logged, surfaced to the user, never swallowed.
- **Framework-first** — built-in Laravel/Filament features take priority over custom code; security primitives (auth, sessions, authz) are never re-implemented.
- **No regex for parsing** — use proper parsers and library APIs.
- **Strict dependency management** — no new dependency without justification.
- **Quality gates**: `vendor/bin/pint --test` and `vendor/bin/pest` must pass with zero warnings or failures before any story is considered done.
- **Tests** cover business logic only; each test is self-sufficient, independent, and deterministic.

## Sources Supported

| Source                      | Alert Types                            | Writeback        |
| --------------------------- | -------------------------------------- | ---------------- |
| AzDO Advanced Security      | Code, Dependency, Secret               | State + Comments |
| AppScan on Cloud (ASoC)     | Vulnerability, Code Quality            | State + Comments |
| Detectify                   | Misconfiguration, Vulnerability        | State            |
| Defender for Cloud > DevOps | Code, Dependency, Secret, IaC, Posture | Read-only        |

## Trackers Supported

| Tracker       | Features                                                                                         |
| ------------- | ------------------------------------------------------------------------------------------------ |
| Jira Cloud    | Create + update issues (single and grouped), labels, priority, assignee, parent, ADF description |
| GitHub Issues | Create + update issues (single and grouped), labels, milestone, assignee, Markdown description   |
