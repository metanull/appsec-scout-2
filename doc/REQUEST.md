# YOUR TASK

Your task is to plan the work defined in this document. Creating Epics and Stories.

I intend to create a new GitHub project for this work and file it as **Epics and Stories** in GitHub.

* Each Epic/Story must cover a **single feature/change**, be **self-contained**, and provide all necessary context for implementation.
* Epics and stories must be **free of assumptions**, and must not require further clarification, investigation, or architectural/implementation decisions.
* All analysis and user questioning must happen **now (upfront)**.
* You must investigate the current repository and ask all questions **now**, clearing ambiguities by challenging inconsistencies and surfacing choices.
* You must properly investigate the data model and API calls of older iterations to avoid hiccups—several upstream sources have **typed alerts** with different structures depending on the alert type.
* You may even make API calls yourself to validate assumptions or clarify doubts (PATs are available in the `.env` / config files of the various apps, and I can provide others).
* We do not want over-simplification: good triage requires access to meaningful data and hyperlinks, so the operator does not need to leave the system to consult external sources.
* You must not persist any changes to this repo (no PR, no commit).

### Expected deliverable

You must design the solution and produce a series of **Markdown documents** describing in details the implementation plan, ideally with the structure:

* **Milestone**: defines/describes the milestone objectives, features, constraints, etc.
  * **Epics** (as many as needed): group and order work while respecting dependencies (do not place a story in Epic 1 if it depends on a story in Epic 2, since stories will be executed in order). Epics provide context, goals, and rules for their child stories.
    * **Stories** (as many as needed): keep them independent where possible; each story is responsible for a single change and includes: goal, context, solution description, and definition of done.

## Historical Context

This repository is about building a tool that supports my day-to-day work: triaging a large number of application security alerts coming from multiple sources—**AppScan on Cloud (ASoC)**, **Azure DevOps (AzDO)**, **Detectify**, and **Defender for Cloud > DevOps**. The goal is to provide a **single interface** to:

* Display vulnerabilities from all sources in one place
* Perform triage actions (change severity, change status, add comments)
* Present all relevant details (including **source-specific remediation information**)
* Facilitate the creation of remediation tasks by **grouping and documenting similar issues** in **Jira** or **GitHub**

### History / previous iterations

I initially developed a **Node.js TUI** application dedicated to **ASoC**. Then I built a separate one for **AzDO**, and much later another for **Detectify** (less polished, but operational). These tools worked well and gave satisfaction. However, they were **distinct applications**. I wanted a more integrated solution and to reduce development effort, because all of these apps essentially provide the same features, only customized per “source”.

So I wrote a second application (still in **Node.js**) where each upstream source was implemented as a “plugin”, to facilitate extensibility (I already had in mind adding **Defender for Cloud > DevOps**). This version introduced two major improvements:

1. **A local database**, allowing me to fetch all vulnerabilities once and then work quickly from cached data instead of querying live sources constantly.
2. **A transformation layer**, converting each source’s data model into a **common representation**.

This version worked well on my personal machine, but I discovered that on my corporate machine (this machine), **security policies and network policies** (notably the fact that HTTP proxy settings must be configurable) made it almost impossible to run the app reliably. Problems would always occur somewhere across: running the app, managing plugins (`npm` packages), or writing the database file.

In addition, the `npm` plugin system turned out to be unnecessary: since I’m developing alone, a truly “pluggable” architecture provides little value.

### .NET rewrites

I then rewrote the application in **.NET**, added an **MSI installer**, and hoped that once installed, the application would be vetted and would run without issues. It mostly worked, but it was still somewhat problematic under corporate security policies, because I had to switch accounts to perform the installation.

Moreover, despite working on “my” corporate workstation, I was unable to successfully install it on a dedicated “security work” corporate VM (different policy regarding internet access).

I then rewrote the app again in **.NET + Avalonia**, hoping I could run the desktop UI inside a **Linux Docker container**. There I faced challenges around rendering the UI: running an X11 display server on the corporate machine was painful. I have now identified that **NoVNC** is a better approach than X11 for this environment.

Both .NET versions provide relative satisfaction, but they have lost some of the polish and value present in the earlier iterations.

I also added support for **Defender for Cloud > DevOps**, but it is not yet stable: Defender requires **MFA with a physical key** that is only available when connecting from the corporate network. This made development difficult, especially because I mostly develop from home (and I have limited permissions on my corporate laptop).

### Common characteristics across all iterations

All iterations preserved a **dual interface**:

* **CLI** (always useful for scripting and ad-hoc queries)
* **TUI / Desktop UI**

The decision to keep a “desktop app” approach was primarily driven by the fact that **personal access tokens (PATs)** are used to access upstream sources as well as Jira/GitHub. All actions are performed under **my identity**, so a service/daemon or shared application model was not appropriate.

***

## Objective of the new system

I want to develop a new application that consolidates everything I’ve learned so far: start fresh, keep what worked well, abandon what worked poorly, and return to a stack I master very well:

* **PHP 8.4**
* **MySQL / MariaDB**
* **Laravel 13**
* **Filament 5**

Laravel will model and service access to all upstream sources (**ASoC, AzDO, Detectify, Defender**) as well as **Jira** and **GitHub**.

### Operating model (new approach)

The operating model changes significantly:

* The system fetches upstream data through **background jobs** on a regular basis.
* Users **edit only the local database**.
* Upstream sources remain untouched **except** when a designated **Sync** operator explicitly propagates changes upstream (see below).

***

## Roles and permissions in the new system

Users can have the following roles:

### Reader Role

* Can view all alerts and related data.

### Triage Role

* Reader + can update alert state, severity, and add comments.
* All edits are stored with timestamp and author identity, so the UI can present alerts together with the full action history.

**Commands (triage operator only):**
The triage operator can run special commands executed by the backend **in their name** (using their configured PATs). These commands are **Artisan commands** in a dedicated namespace: `triage:*`. At the time of writing, we foresee three:

* `triage:trivy {git_url}`
  Clones a Git repository, runs **Trivy**, captures produced **SARIF** reports, and attaches them to the alert.
  *The container must include Trivy.*

* `triage:bfg {git_url} {secret_list}`
  Clones a Git repository, runs **bfg-1.15.0.jar** with the provided `secret_list`, verifies whether BFG found issues, and attaches findings as a JSON object to the alert.
  *The container must include a Java runtime (current LTS) + BFG Repo-Cleaner (latest version; 1.15.0 is expected).*

* `triage:codesearch {PAT} {search} [{project|repo_url}]`
  Runs a code search on Azure DevOps (optionally constrained to a single repository or project) and attaches findings as a JSON object (including hyperlinks) to the alert.
  *This requires the user’s AzDO PAT.* When running from the command line, the PAT is given as a parameter; when used from the web UI byt the Triage operator, their PAT is fetched automatically from the profile

### Plan Role

* Reader + can link/unlink alerts to “work” (Jira/GitHub stories).
* Can also create work (create Jira/GitHub stories for a selection of alerts).
* All edits are stored with timestamp and author identity so the UI can present the history.

**Important:** “Create work” is enabled only if the user has a valid PAT for Jira and/or GitHub.

* The PAT is stored in the user profile.
* It can be tested at any time (a harmless query is sent to Jira/GitHub).
* It can be changed at any time.

### Sync Role

* Reader + the only role allowed to propagate changes back to upstream sources.
* Sync operators select alerts that have local changes (easy to identify thanks to edit history).
* Sync leaves a trace in the alert audit history: alerts for which the latest edit is a **successful sync** cannot be propagated again.

Sync is only available for upstream sources for which the operator has a PAT configured. Upstream actions are **always performed in the operator’s name**.

### Admin Role

* Reader + manages users, queues, and system-level PATs used by background jobs.
* Can view and manage queues and schedules.

### Role combinations

* Reader, Triage, Sync, and Plan can be combined.
* Admin **cannot** be combined with the others (but Admin implicitly includes Reader).

***

## Architecture and deployment constraints of the new system

The application must be designed **from day one** as a **containerized Linux application**.

* HTTP proxy support is required (configured once, used by all connectors to upstream sources and Jira/GitHub).
* VS Code will run **inside the container** during development.
* The application will later be deployed on a server as a **Docker daemon**.

***

## UI / Filament structure of the new system

The UI uses **Filament only** (Filament is not “admin-only”; it is the chosen UI framework).

* **Unauthenticated users** → no access → redirect to login.

### Reader UI

* Dashboard page: alert statistics
* Alerts list page: list all alerts with filtering, search, and ordering
* Alert page: relation-manager-style view presenting one alert and all relations

### Triage / Sync / Plan UI

* Alerts list and alert page include additional controls
* Multi-selection + select all/none on alerts list page for bulk operations and planning
* Profile page + manage user's own list of PATs for upstream sources + jira + gituhb (all optional)

### Admin UI

* Manage users
* Manage system PATs used by background jobs (view, validate, change)
* Manage background jobs (view state, change schedule, manual trigger)
* Audit log
* Error log (any warning/error from upstream sources + Jira/GitHub must be tracked)

### Sync operation (propagate upstream)

A Sync operator sends updates to the upstream source (status, severity, and/or comments) when the source supports it. The audit logs the sending and the result (success/failure).

### Background jobs

#### 1) Upstream fetch

Purpose: gather new alerts, alert updates, alert metadata, and resolution data.

* Runs on a schedule and can be manually triggered by Admin.
* Keeps execution history in the audit log.
* When supported by the source, fetch queries are limited to a timeframe (updates since last successful run).
* When incremental fetching is not possible, or in case of collisions, local records are overwritten instead of duplicated (all sources have unique IDs of some sort).

#### 2) Jira/GitHub fetch

Purpose: gather status updates on linked stories.

## Development Rules for the new system

### Core Principles

* **Fail-fast, no degradation, no hidden fallbacks**
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

***

### Testing Standards

* Tests must be:
  * **Self-sufficient** (no reliance on external state or shared fixtures)
  * **Independent** (no inter-test dependencies)
  * **Deterministic** (no assumptions about existing data)
  * **Single-responsibility** (each test verifies one precise behavior)
  * **Explicitly named** (self-explanatory purpose)

* Each test must:
  * Define and prepare its own data explicitly
  * Validate **business logic only**, not framework internals

***

### Quality Gates

* Code must pass:
  * **Linting (Laravel Pint)** → no warnings, no errors
  * **Testing (Pest)** → all tests passing, no skipped or unstable tests

* No code is considered complete unless all quality checks are satisfied.

***

### Security Requirements

All development must comply with established secure coding practices, with a strong preference for **framework-managed and battle-tested mechanisms**.

#### General rules

* Prefer **framework-managed implementations** over custom code for common concerns

* Use **framework APIs** for operating system interactions
  * Direct execution of OS commands from the application is prohibited

* Implement proper **synchronization mechanisms** to prevent race conditions

* Protect all shared resources and variables against **concurrent access issues**

* Always:
  * Explicitly initialize variables and data stores
  * Avoid implicit or undefined states

* When elevated privileges are required:
  * **Grant them as late as possible**
  * **Drop them as early as possible**

* Avoid calculation and precision issues by understanding the underlying data representation of the language

* Never pass **user-supplied data** to:
  * Dynamic execution functions
  * Command execution contexts
  * Unsafe deserialization mechanisms

* All third-party code and secondary tools must be:
  * Justified (clear business need)
  * Reviewed for security and maintenance status

#### Reference

* OWASP Laravel Cheat Sheet:
  <https://cheatsheetseries.owasp.org/cheatsheets/Laravel_Cheat_Sheet.html>
