# AppSec Scout — User Guide

A short, task-oriented guide to the AppSec Scout web UI for someone running the stack on their
own machine with Docker. It follows the three things you do day to day:

1. **Collect**: pull alerts and scan results into the local database.
2. **Triage**: review, filter, re-state and re-rate what came in.
3. **Report**: raise tracker issues (Jira / GitHub) and optionally push state back to the Source.

This guide sticks to *what to click and what happens*. For the reasoning and the internals, see
[concepts/triage.md](concepts/triage.md), [concepts/integration.md](concepts/integration.md),
[operations.md](operations.md) and [admin.md](admin.md). For installation, see
[install.md](install.md) or [QUICKSTART.md](QUICKSTART.md).

## Contents

- [Before you start](#before-you-start)
- [Navigation map](#navigation-map)
- [Collect](#collect)
- [Triage](#triage)
- [Report](#report)
- [Inventory pages](#inventory-pages)
- [Admin pages](#admin-pages)
- [Dependency-Track](#dependency-track)
- [Useful Docker commands](#useful-docker-commands)
- [Useful Artisan commands](#useful-artisan-commands)
- [Troubleshooting](#troubleshooting)

---

## Before you start

| URL | What |
| --- | --- |
| `http://localhost:8080/` | AppSec Scout (this UI) |
| `http://localhost:8090/` | Dependency-Track UI (see [Dependency-Track](#dependency-track)) |
| `http://localhost:8091/` | Dependency-Track API |

**First sign-in.** Use the bootstrap admin account: `admin@example.com` / `changeme-now`, unless
`BOOTSTRAP_ADMIN_EMAIL` / `BOOTSTRAP_ADMIN_PASSWORD` were set in the root `.env` before the first
start (see [install.md → First Login](install.md#first-login)). Change the password afterwards. Every
local account must enroll an authenticator app (TOTP) at first login. Keep the recovery codes
you are shown.

**Two kinds of credentials.** AppSec Scout keeps two separate sets of integration credentials.
Which set an action uses depends on who started it:

| Set | Where to edit it | Used by |
| --- | --- | --- |
| **System credentials** | *Admin → System Credentials* | Everything on the Operations page and every background job: fetching alerts, refreshing trackers, inventory sync, repository collection, static analysis |
| **Your personal credentials** | *Profile integrations* (sidebar or user menu) | What you do yourself: *Create work item*, *Link existing*, and *Push to source* |

For a single-user setup it is normal to enter the same tokens in both places. A missing
credential is never silently ignored: the action fails with an error, or (for tracker actions)
offers you a link to *Profile integrations*.

**Roles.** What you can see and click depends on your roles. Each role adds to the one before
it: **Reader** (view) → **Triage** (change state/severity, comment) → **Plan** (create/link work
items, curate links and mappings) → **Sync** (push to source, operations) → **Admin** (users,
system credentials, queues, logs). See [roles/](roles/) for the details.

---

## Navigation map

| Sidebar group | Page | Use it to |
| --- | --- | --- |
| — | **Dashboard** | Get an overview of alerts and local findings by state, severity, system and source |
| — | **Profile** / **Profile integrations** | Change your password; manage your personal credentials |
| Reader | **Alerts** | Triage alerts that came from Sources (AzDO Advanced Security, ASoC, Detectify) |
| Reader | **Local Findings** | Triage findings from AppSec Scout's own scans (Trivy, static analysis) |
| Reader | **Dependencies** | Browse the components found in SBOMs |
| Reader | **Software Assets** / **Software Systems** / **Containers** | Browse the inventory hierarchy that alerts belong to |
| Admin | **System Credentials**, **Repository Providers**, **Errors**, **Audit Log**, **Users** | Administration |
| Operations | **Operations** | Start fetches, syncs and scans |
| Operations | **Sync Runs**, **Collection Runs**, **Analysis Runs** | Follow the history of those runs |
| Operations | **Queues**, **Failed Jobs** | Watch and fix background jobs |
| Sync | **Pending Sync** | Push your staged state changes back to the Source |

Inventory hierarchy: **Software Asset** → **Software System** (e.g. an AzDO project) →
**Container** (e.g. a repository). Alerts, local findings and dependencies hang off a Container
or System. See [concepts/asset-system-container-alert.md](concepts/asset-system-container-alert.md).

---

## Collect

Nothing is fetched on a schedule. **You trigger every collection yourself** from
*Operations → Operations*. Each button queues a background job and returns straight away. Follow
progress in the tabs on the same page (*Activity* / *Problems*), or on *Sync Runs*,
*Collection Runs* and *Analysis Runs*. Every action writes an audit record.

### Operations → Operations

| Action | What it does |
| --- | --- |
| **Operations → Fetch source** | Pick a Source (*Azure DevOps Advanced Security*, *HCL AppScan on Cloud*, *Detectify*). Queues a job that uses the **system** credential to pull new and changed alerts since that source's last successful run, upserts them as Alerts, and refreshes the Systems/Containers seen along the way. Recorded as a Sync Run. |
| **Operations → Refresh tracker** | Pick a Tracker (*GitHub Issues*, *Jira Cloud*). Queues a job that re-reads every work item already linked to an alert and updates its title, state and URL locally. Nothing new is created. |
| **Operations → Reconcile all tracker links** | (confirm) Queues a sweep that searches every tracker configured with system credentials for existing issues mentioning an alert's URL and links any it finds. Use it after people created tickets by hand outside AppSec Scout. Recorded as a Sync Run under `reconciliation`. |
| **Operations → Sync inventory** | (confirm, Admin) Queues a job that creates or updates Software Systems and Containers from every Source and Source Control provider that has system credentials. It **does not fetch alerts**. Run it first on a fresh install so alerts have somewhere to land. Recorded under `inventory`. |
| **Operations → Collect repositories** | (confirm, Admin) Clones every Azure DevOps repository visible to the `azdo-repos` system credential and runs Trivy on each: SBOM, vulnerabilities and secrets. It also reports build output and binaries committed to Git (`bin/`, `obj/`, `publish/`, `dist/`, `node_modules/`, `.dll`, `.jar`…) as one `repo-hygiene/*` Local Finding per folder, HIGH when the folder also holds a secret. Results are attached to the matching Container and parsed into **Dependencies** and **Local Findings**. SBOMs are also pushed to Dependency-Track. Runs in the separate `collector` container; follow it on *Collection Runs*. |
| **Operations → Run static analysis** | (confirm, Admin) Same sweep, but runs Opengrep, Roslynator (.NET) and SpotBugs + Find Security Bugs (Java). Repositories whose head commit hasn't changed since the last clean analysis are skipped. Tick **Force full re-analysis** to re-analyse everything, e.g. after upgrading the collector image. Results appear as Local Findings; follow it on *Analysis Runs*. |
| **Operations → Maintenance → Prune audit logs / Prune error logs / Prune failed jobs** | (confirm) Immediately deletes rows older than their retention period (365 / 90 / 90 days by default). The same pruning also runs daily on its own. |

A suggested first-time order: **Sync inventory** → **Fetch source** (once per Source) →
**Collect repositories** / **Run static analysis** → **Reconcile all tracker links**.

### Following the work

| Page | Actions and their effect |
| --- | --- |
| **Sync Runs** | Read-only history of fetches, pushes, inventory syncs and reconciliations, with status, duration and counts. **View error** shows the failure message. |
| **Collection Runs** / **Analysis Runs** | One row per sweep, with repositories succeeded/failed/skipped. **View failures** opens *Errors* filtered to that run. **Force-finish** (confirm) marks a run stuck in "running" as failed so you can start a new one; jobs already running are not stopped. |
| **Queues** | Jobs waiting to run, on every queue. **Drop** (confirm) removes a pending job so it never runs. |
| **Failed Jobs** | Jobs that gave up after their retries. **Retry** puts the job back on its queue. **Forget** (confirm) discards it. Both also work as bulk actions (**Retry selected** / **Forget selected**). **Clean up now** deletes failed jobs past retention. |

The Dashboard and the Operations page also show stat cards (*Reconciliation*, *Inventory sync*,
*Queued jobs*, *Failed jobs*) that link to these pages.

### Scans started from the host

Organisation-wide scans can also be started from PowerShell instead of the buttons above. The
results are imported automatically within a minute:

```powershell
.\scripts\invoke-ops.ps1 -SbomScan -Credential (Get-Credential)
.\scripts\invoke-ops.ps1 -StaticAnalysis -Credential (Get-Credential)
```

See [concepts/sbom-and-static-analysis.md](concepts/sbom-and-static-analysis.md).

---

## Triage

Triage is **local-first**. Changing an alert's state or severity never touches the Source by
itself. The change is *staged* (shown as **Sync: Pending**) until someone pushes it from
*Pending Sync* (see [Report](#report)). You can use AppSec Scout entirely on its own and never
push anything.

### Dashboard

Pairs of tables and pie charts: Alerts and Local Findings by state, by work-item status, by
software system, by type/kind and by severity, plus Open Alerts by Source. **Click any row** to
open the Alerts or Local Findings list already filtered to that slice. Admins also see recent
sync runs, failed jobs and errors. The figures refresh after every sync run.

### Alerts (list)

- Opens by default on **state = Open, severity = Critical + High**. Your last
  filter/search/sort is remembered per user.
- **Search** covers title, description, rule id and the raw source payload.
- **Filters**: severity, state, source, asset, system, container, type, work-item status, *Has
  work item*, *Pending sync*, tags, tracker + work item id.

Row actions (⋮ menu):

| Action | Effect |
| --- | --- |
| **Alerts → Change state** | Choose a new state and give a comment (at least 10 characters). Stores a *pending* state plus a local comment and flags the alert **Pending sync**. The visible state does not change until it is pushed. |
| **Alerts → Change severity** | Same, for severity. If the Source can't accept severity changes, the value stays as a local annotation when you push. |
| **Alerts → Create work item** | Creates a Jira/GitHub issue **immediately**, using your **personal** tracker credential. Choose tracker, project, item type, and where supported priority, labels, assignee and parent. The title and description are generated from the alert. The optional **Comment** (Markdown, e.g. your triage write-up) is posted as a comment on the new issue right after it is created, converted for Jira; it is limited to what the tracker accepts (Jira 32,767, GitHub 65,536 characters). If posting the comment fails, the issue still exists and is linked: a warning says so and the error is in the Error log, so you can add the comment by hand. The issue is linked to the alert, and the tracker/project is remembered as the default for that System/Container. |
| **Alerts → Link existing work item** | Search for an existing issue and link it, without creating anything. |

Bulk actions (select rows):

| Action | Effect |
| --- | --- |
| **Change state (bulk)** | (confirm) Stages the same state change and comment on every selected alert. |
| **Create grouped work item** | Creates **one** issue for all selected alerts, with a grouped description that includes a severity table and per-type sections, and links it to each of them. |
| **Link existing** | Links one existing issue to all selected alerts. |

### Alert detail page

Header actions:

| Action | Effect |
| --- | --- |
| **Change state** / **Change severity** | As above; the result reads "queued for sync review". |
| **Reload from source** | (confirm, Sync) Re-fetches this one alert from its Source with the system credential and updates it in place. Your staged changes are kept. |
| **Create work item** / **Link existing** | As above. |
| **Find existing work items** | (confirm) Searches the tracker projects mapped to this alert's System/Container for issues mentioning the alert's URL, and links them. If no project is mapped it searches every project and warns you. Runs immediately. |
| **Add attachment** | Upload a file (screenshot, evidence) to the alert. |

At the bottom of the *Summary* tab, **View alert** opens the alert at its Source. **View source**
opens the alert's file at the current code (the alert's branch). **View source (commit)** opens it
at the commit the alert was raised on. The same two links appear in *Links & References*. Scanners
often report a path that includes the build's checkout folder (e.g. `project/src/App.java`), so
for an Azure DevOps repository these links first look up where the file really is, using **your
Azure DevOps Repos credential** (*Profile → Integrations*). Without that credential, or when the
file is not in the repository, you return to the alert with a notification saying why.

Tabs at the bottom:

- **Comments**: comments synced from the Source (*From source*) and your own (*Local*). **Add
  comment** stores a local comment. A comment on its own can't be pushed to any Source and
  stays local. You can **Edit** your own local comment for 5 minutes after posting it.
- **Curated links**: hand-picked reference links. **Add curated link** / **Edit** / **Delete**.
- **Work Items**: linked tracker issues and their last known state. **Unlink** (confirm)
  removes only the local link; the issue itself is not touched.
- **Attachments**: **Download**, **Delete** (confirm).
- **Audit History**: everything that happened to this alert. **Payload** shows the raw detail.

### Local Findings

Findings produced by AppSec Scout's own scans (Trivy vulnerabilities and secrets, static
analysis). They have no upstream Source, so **changes apply immediately** and there is nothing
to push. The default view and filters work the same way as on Alerts (kind, status, severity,
system, *Correlated to alert*, …).

| Action | Effect |
| --- | --- |
| **Change status** (row, bulk, detail) | Changes the status right away and records your comment. |
| **Change severity** | Sets a local severity override right away. |
| **Create work item** / **Create grouped work item** / **Link existing** | Same as for alerts. |
| **Unlink correlation** (detail, confirm) | Detaches the finding from the Source alert it was matched to. A later re-scan may match them again. |

Tabs: **Comments** (*Add comment*) and **Work Items** (*Unlink*).

### Dependencies

Read-only list of components (name, version, ecosystem, license) found in SBOMs. You can group
it by *Component* ("where is log4j used?") or by *Used by*. Use **Removed** to show components
that have disappeared from the latest scan.

---

## Report

### Tracker issues

Use **Create work item**, **Create grouped work item**, **Link existing** and **Find existing
work items** (see above). They need:

- a **personal** tracker credential under *Profile integrations* (GitHub *Personal Access
  Token*, or Jira *Host URL* + *Email* + *API Token*);
- ideally, a **Tracker project link** on the System or Container, so the right project is
  pre-selected. It is learned automatically the first time you create or link an issue, or
  when reconciliation finds an issue referencing one of the System's alerts, and can be edited
  by hand (see [Inventory pages](#inventory-pages)).

Keep linked issue states current with **Operations → Refresh tracker**. Pick up issues created
outside the app with **Operations → Reconcile all tracker links**. The *Alerts by Work Item
status* dashboard widgets show how much remains to be reported.

### Sync → Pending Sync (push state back to the Source)

Lists every alert with staged changes, grouped by Source, showing current vs pending state and
severity. Needs the Sync role.

| Action | Effect |
| --- | --- |
| **Pending Sync → Push to source** (bulk, confirm) | Pushes each Source in turn, right away, using **your personal Source credential**, and reports each Source's outcome. On success the pending state becomes the real state, the pending flag is cleared, and the push is recorded as a Sync Run. When a push fails, AppSec Scout reads the alert back from the Source. If the Source already has the pending state (for example, someone dismissed the alert directly in Azure DevOps, which then answers *409 Conflict*), the change is marked synced and a system note says the justification comment was not pushed. Azure DevOps cannot read some alerts on their own (it answers *400 Sequence contains more than one element*), so for those AppSec Scout reads the alert through the repository's alert list instead. Otherwise the alert stays pending and an error is logged (see *Errors*, tab *Sync*). A later push retries it, but after 3 failed attempts it is no longer pushed, only checked against the Source again. Changes the Source cannot accept (severity on some Sources, standalone comments) are kept locally, with a system note on the alert. |
| **Pending Sync → Discard pending change** (bulk, confirm; also needs `alerts.edit`) | Drops the selected alerts' unpushed state, severity and comment changes without pushing them, and clears their failed-push retries. Each alert keeps the state last read from its Source; a note on the alert and the audit log record what was discarded. Use it for a change the Source keeps refusing, or one you no longer want pushed. |

What each Source accepts: AzDO and ASoC take a state change together with its comment; Detectify takes state only. See
[concepts/upstream-source-capabilities.md](concepts/upstream-source-capabilities.md).

---

## Inventory pages

| Page | What you can do |
| --- | --- |
| **Software Assets** | The only inventory level you create by hand: group related Systems under a business asset. **New**, **Edit**, **Delete** need the Plan role. Delete removes the asset's own links, mappings and attachments; its Systems are kept, just unassigned. Under *Software systems*: **Link software system** / **Unlink**. |
| **Software Systems** | Read-only list (created by *Sync inventory* / *Fetch source*), with open/critical/high counts. Tabs for its alerts, containers and the shared tabs below. |
| **Containers** | Read-only list of repositories, sites and other scan targets, with open-alert counts and the shared tabs below. |

Shared tabs on Assets, Systems and Containers:

| Tab | Actions and their effect |
| --- | --- |
| **Curated links** | **Add curated link** (label, kind, URL), **Edit**, **Delete**. Shown in the "Links & References" section. |
| **Tracker project links** | **Add project link** (tracker, project key, *Default project for this owner*), **Edit**, **Delete**. Sets the default tracker/project on work-item forms. Never limits where *Find existing work items* searches. |
| **Repository mappings** | **Add mapping** (provider, repository, default branch, path prefix), **Edit**, **Delete**. Used to build source-code links on alerts. A Container's mapping overrides its System's. |
| **Attachments** | **Add attachment** (kind, file). An `sbom`, `vulnerabilities`, `secrets` or code-quality report is parsed into Dependencies / Local Findings, and an SBOM on a Container is also sent to Dependency-Track. **Download**, **Delete**. |
| **Dependencies** / **Local findings** | Read-only, with **Download SBOM** / **Download findings** when a report is attached. |

---

## Admin pages

| Page | Actions and their effect |
| --- | --- |
| **System Credentials** | One section per integration: AzDO Advanced Security, ASoC, Detectify, GitHub Issues, Jira Cloud, AzDO Repos, GitHub Repos, Bitbucket, Dependency-Track. Stored secrets are masked; use **Replace** to change one. **Test** saves and tests one integration; **Test all configured** tests everything already saved; **Save all changes** saves the page; **Delete** (shown once something is stored, asks for confirmation) removes every stored value of that one integration, e.g. to disable it. These credentials are used by every Operations action. |
| **Profile integrations** | The same form (minus Dependency-Track) for **your own** credentials, used by the actions you run yourself. |
| **Users** | **New**; row menu **Edit** (name, email, roles, disabled), **Reset 2FA** (forces re-enrolment), **Send password reset** (email), **Disable user** (also ends their sessions) / **Enable user**. |
| **Repository Providers** | **New** / **Edit** / **Delete** for repository hosts (Azure Repos, GitHub, Bitbucket) that repository mappings point to. Deleting a provider also deletes its mappings. |
| **Errors** | Application and job errors, with tabs per area (Sync, Repository Collection, Static Analysis, SBOM Import, …). **Clean up now** prunes entries past retention. |
| **Audit Log** | Every write action: who did it, what changed, and when. **Clean up now** prunes entries past retention. |

---

## Dependency-Track

The stack ships a **Dependency-Track** instance for browsing SBOMs, components and their known
vulnerabilities. It is set up automatically on first start by the one-shot
`dependencytrack-bootstrap` container, which:

- changes the default admin password,
- creates an `Automation` team and its API key,
- connects Dependency-Track to the bundled Trivy server,
- and stores the results in AppSec Scout's credential vault.

Every SBOM attached to a Container (from *Collect repositories*, `invoke-ops.ps1 -SbomScan`, or a
manual upload) is pushed to Dependency-Track automatically.

**Signing in.** Open `http://localhost:8090/` and log in as **`admin`**. The password was
generated during bootstrap and is not shown anywhere in the AppSec Scout UI. Read it from the
vault:

```bash
docker compose exec app php artisan vault:get dependencytrack.adminPassword
```

or, with the helper script:

```powershell
.\scripts\invoke-app.ps1 -Artisan vault:get dependencytrack.adminPassword
```

The value is printed without a trailing newline, so it may run straight into your prompt.
`credentials:system:get` refuses this key; use `vault:get`.

Other useful vault keys: `dependencytrack.apiKey` and `dependencytrack.baseUrl`. Both are also
editable under *Admin → System Credentials → Dependency-Track*. To re-send every SBOM to
Dependency-Track (for example after wiping its volume):

```bash
docker compose exec app php artisan sbom:export-dependency-track
```

---

## Useful Docker commands

Run these from the repository root (or the folder holding `docker-compose.yml`). For a full
reference see [operations.md](operations.md).

| Command | Purpose |
| --- | --- |
| `.\scripts\appsec-scout.ps1` | Build (if needed) and start the stack, wait for health, open the browser |
| `docker compose up -d` | Start everything in the background |
| `docker compose ps` / `docker compose ps -a` | Service status, including one-shot containers that have exited |
| `docker compose stop` | Stop without removing anything |
| `docker compose down` | Stop and remove containers. **Your data survives** in the volumes. |
| `docker compose down -v` | ⚠ Also deletes volumes: **database, attachments, vault, Dependency-Track data** |
| `docker compose restart app` | Restart the app; re-runs migrations and seeding on boot (or `.\scripts\invoke-app.ps1 -Restart`) |
| `docker compose logs -f app` | Follow app logs (also `collector`, `static-analysis-collector`, `dependencytrack-apiserver`, `trivy-server`, `mysql`) |
| `docker compose logs dependencytrack-bootstrap` | Find out why Dependency-Track setup failed |
| `docker compose up -d dependencytrack-bootstrap` | Re-run Dependency-Track setup |
| `docker compose exec app bash` | Shell inside the app container (or `.\scripts\invoke-app.ps1`) |
| `docker compose exec app php artisan <command>` | Run an Artisan command (or `.\scripts\invoke-app.ps1 -Artisan <command>`) |
| `docker compose exec app php artisan tinker` | Interactive PHP console (or `.\scripts\invoke-app.ps1 -Tinker`) |
| `docker compose exec app supervisorctl status` | Check that nginx, php-fpm, the scheduler and the queue worker are running |
| `curl http://localhost:8080/up` | Health check |
| `docker system df` | See how much disk Docker uses (see [operations.md → Disk Usage](operations.md#disk-usage)) |

---

## Useful Artisan commands

Run each one as `docker compose exec app php artisan <command>` (or
`.\scripts\invoke-app.ps1 -Artisan <command>`). `php artisan list` shows everything available.

**Vault and credentials**

| Command | Purpose |
| --- | --- |
| `vault:list` | List every system key stored in the vault (names only) |
| `vault:get <key>` | Print any system vault value, e.g. `dependencytrack.adminPassword` |
| `credentials:system:list` | List the Source/Tracker/Source Control credential keys |
| `credentials:system:get <key>` | Print one of those values, e.g. `azdo.pat` |
| `credentials:system:export <path>` | Export system credentials to JSON. ⚠ The file holds plain-text secrets. |
| `credentials:system:import <path>` | Replace system credentials from such a JSON file |

**Collection and import**

| Command | Purpose |
| --- | --- |
| `assets:sync-azdo-projects [--project-filter=] [--repo-filter=]` | Sync AzDO projects/repositories into the inventory (same idea as *Sync inventory*, AzDO only) |
| `assets:import-attachment <source> <system> <kind> <file> [--container=]` | Attach a report file (e.g. an SBOM) to a System/Container from the command line |
| `assets:reparse-attachments [--kind=]` | Re-parse stored attachments into Dependencies / Local Findings |
| `sbom:import-pending-scans` / `staticanalysis:import-pending-scans` | Import results written by `invoke-ops.ps1` scans now (these also run every minute) |
| `sbom:scan-status` / `staticanalysis:scan-status` | Table of host-triggered scan runs and their import progress |
| `sbom:export-dependency-track` | Re-push every Container's latest SBOM to Dependency-Track |
| `dependencytrack:bootstrap` | Re-run Dependency-Track provisioning by hand (normally done by the bootstrap container) |

**Triage**

| Command | Purpose |
| --- | --- |
| `triage:codesearch "<search>" [--scope=<project or repo URL>] [--attach-to=<alert id>]` | Azure DevOps code search, using the `azdo-repos` credential by default. Prints the hits and can attach them to an alert. |

**Accounts and maintenance**

| Command | Purpose |
| --- | --- |
| `appsec:bootstrap-admin --email= --password=` | Create the first admin when no user exists yet |
| `queue:failed` / `queue:retry all` | Laravel's failed-job list / retry (same as the *Failed Jobs* page) |
| `schedule:list` | Show scheduled tasks: daily pruning, per-minute scan imports |
| `events:recompute-pending-sync` | One-off repair for alerts stuck as "pending sync" |
| `local-findings:repair-file-paths --dry-run` | One-off repair of old absolute or percent-encoded file paths on local findings (drop `--dry-run` to apply) |

---

## Troubleshooting

| Symptom | Where to look |
| --- | --- |
| A button was clicked but nothing new appears | *Queues* (still waiting?), then *Sync Runs* / *Collection Runs* / *Analysis Runs* (status, error), then *Failed Jobs* and *Errors* |
| "Personal tracker credentials required" | Add your tracker token under *Profile integrations* and use **Test** |
| Fetch or sync fails right away | The system credential is missing or wrong: *Admin → System Credentials → Test* |
| "Stored credentials cannot be decrypted (was APP_KEY changed?)" | **Replace** the value and save it again, or **Delete** the integration's credentials |
| A run is stuck in "running" and blocks a new sweep | **Force-finish** it on *Collection Runs* / *Analysis Runs* |
| Alerts land with no System/Container | Run **Sync inventory**, then **Fetch source** again |
| Dependency-Track login fails | `vault:get dependencytrack.adminPassword`; if the key is missing, check `docker compose logs dependencytrack-bootstrap` |
