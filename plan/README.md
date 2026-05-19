# AppSec Scout — Plan (Laravel/Filament/MySQL Rewrite)

Ground-up rewrite in **PHP 8.4 / Laravel 13 / Filament 5 / MySQL**, packaged as a Linux Docker image, replacing the prior Node.js and .NET iterations. Filament is **THE** UI (not just admin). All security primitives are delegated to Laravel/Fortify — never re-implemented.

This plan is sliced into 6 ordered milestones. Each milestone is independently deployable and groups one or more epics. Each epic groups one or more single-responsibility stories.

## Structure

| Milestone | Title | Epics | File |
|---|---|---|---|
| M1 | Foundation | Scaffold · Platform · Security primitives | [M1-foundation.md](M1-foundation.md) |
| M2 | Sources read-only + Reader UI | Domain model · Source plugin contract · AzDO source · ASoC source · Detectify source · Sync orchestrator · Reader UI · Composite systems | [M2-sources-and-reader.md](M2-sources-and-reader.md) |
| M3 | Triage + Sync roles | Local edits · Upstream propagation | [M3-triage-and-sync.md](M3-triage-and-sync.md) |
| M4 | Plan role + Jira/GitHub trackers | Tracker plugin contract · Description builder · Jira tracker · GitHub tracker · Work-item creation · Tracker refresh · Credential management UI | [M4-plan-and-trackers.md](M4-plan-and-trackers.md) |
| M5 | Defender for Cloud + Triage Artisan commands | Defender source · Triage commands · Attachments | [M5-defender-and-triage-commands.md](M5-defender-and-triage-commands.md) |
| M6 | Admin polish + packaging | Admin UI · Image hardening · E2E tests · Documentation | [M6-admin-polish.md](M6-admin-polish.md) |

## Decisions Captured Upfront

| Topic | Decision |
|---|---|
| Authentication | Laravel **Fortify** (email/password + mandatory TOTP 2FA) — no SSO |
| User roles | Reader → Triage → Plan → Sync → Admin (cumulative, via `spatie/laravel-permission`) |
| Sources in scope | AzDO Advanced Security, ASoC, Detectify (M2); Defender for Cloud (M5) |
| Trackers in scope | Jira Cloud, GitHub Issues |
| Defender auth | Service principal (clientId/clientSecret) — assumed available by M5 |
| Triage commands runtime | **Single container image**; Symfony Process with binary allow-list + hardcoded argv arrays (never shell strings) |
| Composite/linked SoftwareSystems | Included from day one (M2) |
| Data migration from prior iterations | **None — start fresh** |
| Tracker (Jira/GitHub) state refresh | Scheduled job pulls state into local DB; alert remains pending until Sync operator propagates |
| ASoC remediation enrichment | Focused-article resolution (IssueTypeId + Language + ApiVulnName), HTML→Markdown, cached 7d |
| Markdown → ADF (Jira) | Custom converter (no battle-tested PHP library); ported from prior iterations; AST walk via `league/commonmark` — no regex |
| HTTP proxy | One `config/proxy.php` honored by every outbound Guzzle client via DI factory |
| Secret storage | Laravel `encrypted` cast — per-user and system PATs in same `credentials` table |
| Queue & scheduler | Laravel queues on **Redis**; Laravel scheduler for periodic jobs |
| Quality gates | **Pest** (all green, none skipped) + **Laravel Pint** (no warnings) — enforced in CI before merge |

## Container Topology

Single image (Debian-slim based) running:

- PHP 8.4 (php-fpm) + Composer
- nginx (reverse proxy in front of php-fpm)
- supervisord orchestrating: php-fpm · nginx · `php artisan schedule:work` · `php artisan queue:work`
- `trivy` (apt)
- OpenJDK 21 JRE headless (for BFG)
- BFG Repo-Cleaner jar at `/opt/bfg/bfg.jar`
- `git`

`docker-compose.yml` for local dev: `app` + `mysql` + `redis`.

## Cross-Cutting Rules

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

## Reference Patterns from Prior Iterations

The prior iterations of AppSec Scout are available as **read-only reference** in the [`legacy-code`](../legacy-code/) submodule (do not modify). Use them when implementing each story to understand historical data models, API integration patterns, and business logic.

| Concern | Reference file(s) |
|---|---|
| Canonical event shape | [legacy-code/core/src/models/security-event.js](../legacy-code/core/src/models/security-event.js) |
| ASoC client + auth retry + pagination | [legacy-code/plugins/source/siem-source-asoc/src/asoc-client.js](../legacy-code/plugins/source/siem-source-asoc/src/asoc-client.js) |
| ASoC typed-issue dispatch | [legacy-code/plugins/source/siem-source-asoc/src/asoc-normalizer.js](../legacy-code/plugins/source/siem-source-asoc/src/asoc-normalizer.js) + [legacy-code/dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCNormalizer.cs](../legacy-code/dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCNormalizer.cs) |
| ASoC focused-article URL resolution | [legacy-code/dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs](../legacy-code/dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs) |
| AzDO alert types | [legacy-code/plugins/source/siem-source-azdo/](../legacy-code/plugins/source/siem-source-azdo/) |
| Detectify findings | [legacy-code/plugins/source/siem-source-detectify/](../legacy-code/plugins/source/siem-source-detectify/) |
| Defender ARG sub-assessments + posture | [legacy-code/plugins/source/siem-source-defender/](../legacy-code/plugins/source/siem-source-defender/) + [legacy-code/dotnet/src/AppSecScout.Core/Sources/Defender/](../legacy-code/dotnet/src/AppSecScout.Core/Sources/Defender/) |
| Jira tracker + Markdown→ADF | [legacy-code/plugins/tracker/siem-tracker-jira/](../legacy-code/plugins/tracker/siem-tracker-jira/) |
| GitHub tracker (state_reason handling) | [legacy-code/plugins/tracker/siem-tracker-github/](../legacy-code/plugins/tracker/siem-tracker-github/) |
| Grouped work-item description builder | [legacy-code/core/src/work-items/description-builder.js](../legacy-code/core/src/work-items/description-builder.js) |
| Composite system data model | [legacy-code/changelog/20260205T075700Z-composite-system.md](../legacy-code/changelog/20260205T075700Z-composite-system.md) |

## Verification per Milestone

1. Per-story DoD met (Pest green, Pint clean, migrations apply, audit row produced).
2. Full `pest` suite green.
3. `docker build` succeeds and `docker compose up` boots the stack with the new functionality available.
4. Manual smoke for each role touched by the milestone.
5. M6: `trivy image appsec-scout:latest` returns no Critical/High; image size <600 MB.

## Story Schema

Every story below follows the same schema:

```
### S<n> — <Title>
**Goal**: one-sentence outcome.
**Context**: why this story exists, what it depends on.
**Solution**: concrete implementation outline — file paths, packages, REST endpoints, classes.
**Definition of Done**: explicit, testable checklist.
**Relevant files** (prior iterations): links to read-only references.
```
