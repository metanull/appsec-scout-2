# AppSec Scout

AppSec Scout is a tool designed to simplify the management of application security alerts. It consolidates alerts from multiple sources into a single interface, making it easier to triage, track, and resolve security issues efficiently.

## What It Does

- **Aggregates Alerts**: Collects security alerts from sources like AppScan on Cloud (ASoC), Azure DevOps Advanced Security (AzDO), Detectify, and Microsoft Defender for Cloud > DevOps.
- **Streamlines Triage**: Provides a unified interface to review, update, and manage alert statuses.
- **Facilitates Remediation**: Enables the creation of remediation tasks in Jira or GitHub Issues directly from the app.

## Why It Matters

Managing security alerts from multiple tools can be overwhelming and time-consuming. AppSec Scout simplifies this process by providing a centralized platform, reducing the need to switch between different systems and ensuring that all alerts are handled consistently.

## Key Features

- Centralized alert management
- Integration with popular security tools
- Support for creating remediation tasks
- Audit logging for all actions

AppSec Scout is built to work seamlessly in environments with strict security and network policies, leveraging a Docker-first deployment model for easy setup and operation.

Operator documentation:

- [docs/install.md](docs/install.md)
- [docs/operations.md](docs/operations.md)
- [docs/admin.md](docs/admin.md)
- [docs/security.md](docs/security.md)
- [docs/architecture.md](docs/architecture.md)

## Stack

| Layer          | Choice      |
| -------------- | ----------- |
| Language       | PHP 8.4     |
| Framework      | Laravel 13  |
| UI             | Filament 5  |
| Database       | MySQL 8.0+  |
| Cache / Queue  | Redis 7     |

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


See [plan/README.md](plan/README.md) for the full decision log and epic/story breakdown.

## Development Rules

* **Fail-fast, no degradation, no implicit/hidden fallbacks**
  The application must be reliable and transparent. It is strictly prohibited to swallow, hide, or disguise errors.
  * All errors **must be logged**.
  * All errors **must be surfaced to the user** in a clear and actionable way.

* **Strict dependency management**
  * No outdated, vulnerable, unmaintained, or marginally used dependencies.
  * Any new dependency **must be explicitly justified and validated with the user before being introduced**.

* **Framework-first approach**
  Always prioritize:

  1. Built-in framework features
  2. Official extensions and components
  3. Vendor best practices

  Custom development must be avoided unless absolutely necessary.

* **Security must not be self-implemented**
  All security-related mechanisms (authentication, authorization, session handling, etc.) must rely exclusively on **framework-provided and well-maintained components**.

* Prefer **framework-managed implementations** over custom code for common concerns

* Use **framework APIs** for operating system interactions
  * Direct execution of OS commands from the application is prohibited

* **Pint clean, Pest green** — verified per story before merge.

## Sources Supported

| Source                      | Alert Types                            | Writeback        |
| --------------------------- | -------------------------------------- | ---------------- |
| AzDO Advanced Security      | Code, Dependency, Secret               | State + Comments |
| AppScan on Cloud (ASoC)     | Vulnerability, Code Quality            | State + Comments |
| Detectify                   | Misconfiguration, Vulnerability        | State            |
| Defender for Cloud > DevOps | Code, Dependency, Secret, IaC, Posture | Deferred (not yet implemented) |

## Trackers Supported

| Tracker       | Features                                                                                         |
| ------------- | ------------------------------------------------------------------------------------------------ |
| Jira Cloud    | Create + update issues (single and grouped), labels, priority, assignee, parent, ADF description |
| GitHub Issues | Create + update issues (single and grouped), labels, milestone, assignee, Markdown description   |

## Toolset

Beyond the Filament app itself, the repository ships a set of Docker-based tools driven exclusively through PowerShell entry points in [scripts/](scripts/README.md) — never bare `docker compose` commands.

### scripts/

All development, CI, and operations tasks go through these entry points; see [scripts/README.md](scripts/README.md) for the full parameter reference of every script.

| Script | Purpose |
|--------|---------|
| `appsec-scout.ps1` | Start/rebuild the application stack |
| `invoke-check.ps1` / `invoke-fix.ps1` | Run CI checks / mutating auto-fixes (lint, tests, static analysis, dependencies) |
| `invoke-claude.ps1` | Run Claude Code in a sandboxed container against this repo |
| `invoke-ops.ps1` | Open an `ops` sandboxed shell, or run an org-wide SBOM/vulnerability/secret scan |
| `test-GitHubToken.ps1` / `test-AzureDevOpsToken.ps1` | Validate a PAT before using it elsewhere |
| `validate-workflows.cjs` | Lint GitHub Actions workflow YAML |

### docker/ops — sandboxed appsec investigation shell

A hands-on container for code analysis, secret scanning, dependency auditing, SBOM generation (Trivy), and Git history cleaning (BFG) against any repository. It has no access to the host filesystem beyond explicit bind-mounts. See [docker/ops/README.md](docker/ops/README.md).

```powershell
.\scripts\invoke-ops.ps1                                              # interactive shell
.\scripts\invoke-ops.ps1 -Mode sbom-scan -AzdoCredential (Get-Credential)  # org-wide SBOM/vuln/secret scan
```

### docker/claude — sandboxed Claude Code container

Runs Claude Code in an isolated, ephemeral container with no host filesystem access — interactively, for one-time OAuth login, or as an autonomous task that clones a repo, does the work, and opens a PR. Driven by `invoke-claude.ps1`; see [scripts/README.md#invoke-claudeps1](scripts/README.md#invoke-claudeps1).

### Dependency-Track — SBOM visualization

[OWASP Dependency-Track](https://dependencytrack.org/) is bundled as an optional Docker Compose profile for visualizing and vulnerability-scanning the SBOMs AppSec Scout already collects. It runs as part of this suite (its own Postgres + apiserver + frontend), not as a standalone tool you configure by hand: a one-shot `dependencytrack-bootstrap` container logs in, performs the forced first-boot password change if needed, grants the automation team the permissions required for BOM uploads, and stores a fresh API key in AppSec Scout's credential vault automatically.

```powershell
# Start Dependency-Track (Postgres + apiserver + frontend) and auto-provision it
docker compose --profile dependencytrack up -d

# Collect SBOMs for every repo in an Azure DevOps org and store them as attachments
.\scripts\invoke-ops.ps1 -Mode sbom-scan -AzdoCredential (Get-Credential)

# Push every container's latest stored SBOM into Dependency-Track
docker compose exec app php artisan sbom:export-dependency-track
```

Frontend: `http://localhost:8090`. Re-running `sbom:export-dependency-track` at any time refreshes existing Dependency-Track projects with the latest scan. `DTRACK_*` variables in `.env` (base URL/port, admin username/password, team name) are all configurable — see `.env.example`.

### tools/

Standalone PowerShell utilities, independent of the Docker Compose stack and the main application:

| Tool | Purpose |
|------|---------|
| [tools/devops-dotnet-versions](tools/devops-dotnet-versions/README.md) | Scans an Azure DevOps organization for .NET project framework versions |
| [tools/internet-facing-http-headers](tools/internet-facing-http-headers/README.md) | Collects HTTP response headers across a list of internet-facing URLs |
