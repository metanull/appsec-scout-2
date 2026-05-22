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

| Layer         | Choice                                                |
| ------------- | ----------------------------------------------------- |
| Language      | PHP 8.4                                               |
| Framework     | Laravel 13                                            |
| UI            | Filament 5      |
| Database      | MySQL 8.0+                                            |

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
| Defender for Cloud > DevOps | Code, Dependency, Secret, IaC, Posture | Read-only        |

## Trackers Supported

| Tracker       | Features                                                                                         |
| ------------- | ------------------------------------------------------------------------------------------------ |
| Jira Cloud    | Create + update issues (single and grouped), labels, priority, assignee, parent, ADF description |
| GitHub Issues | Create + update issues (single and grouped), labels, milestone, assignee, Markdown description   |
