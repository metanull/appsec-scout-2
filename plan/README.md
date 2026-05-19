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

## Cross-Cutting Rules (apply to every story)

- **Framework first** — never re-implement what Laravel/Filament/Fortify already provides.
- **No regex for parsing** — use AST parsers (`league/commonmark`, `symfony/dom-crawler`, `nikic/php-parser`) and structured deserializers.
- **Pint clean, Pest green** — verified per story before merge.
- **Single-responsibility classes**, **methods ≤25 lines**, **no fallbacks**, **fail fast**.
- **No comments unless nonobvious** — code must be self-explanatory.
- **Realistic test data** — built from real recorded API responses, not placeholder/dummy.
- **Audit on every write** — `App\Audit\Recorder` invoked explicitly (never by magic interceptor).
- **Proxy honored on every outbound call** — clients obtain Guzzle from the shared factory only.

## Reference Patterns from Prior Iterations

Use the prior iterations as **read-only reference** when implementing each story.

| Concern | Reference file(s) |
|---|---|
| Canonical event shape | [core/src/models/security-event.js](../core/src/models/security-event.js) |
| ASoC client + auth retry + pagination | [plugins/source/siem-source-asoc/src/asoc-client.js](../plugins/source/siem-source-asoc/src/asoc-client.js) |
| ASoC typed-issue dispatch | [plugins/source/siem-source-asoc/src/asoc-normalizer.js](../plugins/source/siem-source-asoc/src/asoc-normalizer.js) + [dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCNormalizer.cs](../dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCNormalizer.cs) |
| ASoC focused-article URL resolution | [dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs](../dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs) |
| AzDO alert types | [plugins/source/siem-source-azdo/](../plugins/source/siem-source-azdo/) |
| Detectify findings | [plugins/source/siem-source-detectify/](../plugins/source/siem-source-detectify/) |
| Defender ARG sub-assessments + posture | [plugins/source/siem-source-defender/](../plugins/source/siem-source-defender/) + [dotnet/src/AppSecScout.Core/Sources/Defender/](../dotnet/src/AppSecScout.Core/Sources/Defender/) |
| Jira tracker + Markdown→ADF | [plugins/tracker/siem-tracker-jira/](../plugins/tracker/siem-tracker-jira/) |
| GitHub tracker (state_reason handling) | [plugins/tracker/siem-tracker-github/](../plugins/tracker/siem-tracker-github/) |
| Grouped work-item description builder | [core/src/work-items/description-builder.js](../core/src/work-items/description-builder.js) |
| Composite system data model | [changelog/20260205T075700Z-composite-system.md](../changelog/20260205T075700Z-composite-system.md) |

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
