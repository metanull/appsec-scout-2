# M2 — Sources read-only + Reader UI

**Goal**: Three source plugins (AzDO, ASoC, Detectify) fetch security data on a schedule into the local DB. Reader operators browse a rich dashboard / alerts list / alert detail. Composite (linked) software systems supported from day one.

**Outcome**: A Reader can log in and explore the full population of alerts pulled overnight, with type-specific details (secret occurrences, dependency CVEs, code locations with snippets, misconfigurations) presented richly.

---

## Epic E1 — Domain Model

### S1 — Core domain migrations
**Goal**: Canonical tables for `software_systems`, `security_containers`, `security_events`, `event_comments`, `software_system_links`, `software_system_link_members`.
**Context**: All M2+ functionality reads/writes these tables. Schema must match the unified prior-iteration model so plugin normalizers port cleanly.
**Solution**:
- `software_systems` (id, source_id varchar, source_system_id varchar, name, description nullable, url nullable, metadata json, first_seen_at, last_seen_at, synced_at). Unique `(source_id, source_system_id)`.
- `security_containers` (id, software_system_id FK, source_container_id varchar, name, kind varchar nullable, url nullable, metadata json, first_seen_at, last_seen_at, synced_at). Unique `(software_system_id, source_container_id)`.
- `security_events`:
  - `id`, `source_id`, `source_event_id`, `software_system_id` FK, `container_id` FK nullable, `title`, `description` longtext nullable.
  - `severity` enum(`critical`,`high`,`medium`,`low`,`informational`).
  - `state` enum(`open`,`acknowledged`,`in_progress`,`resolved`,`dismissed`).
  - `type` enum(`vulnerability`,`secret`,`dependency`,`license`,`misconfiguration`,`code_quality`,`iac`,`posture`).
  - `rule_id` varchar nullable; `fingerprint` varchar indexed; `url` varchar nullable; `remediation` longtext nullable.
  - Location columns: `file_path`, `start_line`, `end_line`, `snippet` longtext, `commit_sha`, `branch`, `version_control_url` — all nullable.
  - `source_data` mediumtext (JSON, holds raw upstream payload).
  - `metadata` json (CVE, CWE, package, links, scanner, fixGroupId, etc.).
  - `first_seen_at`, `last_seen_at`, `synced_at`, `updated_at`.
  - `is_dirty` boolean default false; `pending_state` enum nullable; `pending_comment` text nullable.
  - Unique `(source_id, source_event_id)`; indexes on `(software_system_id, state)`, `(severity)`, `(fingerprint)`, FULLTEXT `(title, description)`.
- `event_comments` (id, event_id FK, body text, author_user_id nullable=upstream, upstream_comment_id varchar nullable, created_at). Index `(event_id, created_at)`.
- `software_system_links` (id, name, description nullable, created_at, updated_at).
- `software_system_link_members` (link_id FK, software_system_id FK, sort_order). Composite PK `(link_id, software_system_id)`.
**Definition of Done**:
- Migrations apply on fresh MySQL 8.0.
- Eloquent models `SoftwareSystem`, `SecurityContainer`, `SecurityEvent`, `EventComment`, `SoftwareSystemLink` with casts (enums, JSON, dates) and relations.
- Factories produce realistic rows for tests (built from real-recorded source fixtures).
- Pest tests for cast round-trips and unique constraints.
**Relevant files**: [core/src/models/security-event.js](../core/src/models/security-event.js), [dotnet/src/AppSecScout.Core/Models/](../dotnet/src/AppSecScout.Core/Models/).

---

## Epic E2 — Source Plugin Contract

### S2 — Source contract + registry
**Goal**: PHP interfaces describing what a source plugin must implement.
**Context**: All concrete sources (AzDO, ASoC, Detectify, Defender) implement this contract; the Sync orchestrator depends only on the contract.
**Solution**:
- Interface `App\Sources\Contracts\Source`:
  - `id(): string`, `displayName(): string`
  - `capabilities(): SourceCapabilities`
  - `requiredCredentialKeys(): array<string>`
  - `testConnection(): TestResult`
  - `fetchSystems(): iterable<SystemDto>`
  - `fetchContainers(SystemDto $system): iterable<ContainerDto>`
  - `fetchEvents(?Carbon $since, ?SystemDto $system = null): iterable<EventDto>`
  - `pushEventState(SecurityEvent $event): PushResult`
  - `fetchRawEvent(SecurityEvent $event): EventDto`
  - `enrichEvent(SecurityEvent $event): ?EventDto`
- Value object `SourceCapabilities` (`hasContainers`, `canUpdateState`, `canUpdateSeverity`, `canAddComments`, `supportedEventTypes`).
- `App\Sources\Registry` collects implementations tagged `appsec-scout.source` from the container; returns enabled sources based on `integration_settings.<id>.enabled`.
**Definition of Done**:
- Parameterized Pest contract test (`SourceContractTest`) runs against every registered source — initially only a fake implementation for green CI.
- Registry returns only enabled sources (Pest test).

---

## Epic E3 — AzDO Source

### S3 — AzDO source: client + code alerts
**Goal**: Fetch projects, repos, and code-type alerts.
**Context**: AzDO Advanced Security exposes code/dependency/secret alert kinds with different payload shapes; code alerts first.
**Solution**:
- `App\Sources\AzDo\AzDoClient` constructed via `OutboundHttpFactory` with `Authorization: Basic base64(":<pat>")`.
- Endpoints (api-version 7.1-preview.1):
  - `GET /_apis/projects?stateFilter=wellFormed&$top=...`
  - `GET /{project}/_apis/git/repositories`
  - `GET /{project}/_apis/alert/repositories/{repoId}/alerts?alertType=code&criteria.modifiedSince={iso}`
  - `GET /{project}/_apis/alert/repositories/{repoId}/alerts/{alertId}`
- Normalizer maps `alertType=code` to `type=vulnerability` or `code_quality` (based on `tool.name`):
  - Location from `physicalLocations[0]`: file path, start/end line, snippet, commitSha, branch, versionControlUrl.
  - State: `active→open`, `dismissed→dismissed`, `fixed→resolved`.
  - Severity from `severity` (or `properties.severity`).
- Capabilities: `hasContainers=true, canUpdateState=true, canUpdateSeverity=false, canAddComments=true, supportedEventTypes=[vulnerability, code_quality]`.
**Definition of Done**:
- Recorded fixtures committed under `tests/Fixtures/AzDo/` (captured once from a real instance, redacted).
- Pest tests for `fetchSystems`, `fetchContainers`, `fetchEvents(code)` against fixtures.
- Incremental fetch via `criteria.modifiedSince` cursor (Pest test).
- Idempotent: second fetch produces zero inserts and exact zero updates when nothing changed.
**Relevant files**: [plugins/source/siem-source-azdo/](../plugins/source/siem-source-azdo/).

### S4 — AzDO source: dependency alerts
**Goal**: Same client extended to `alertType=dependency`.
**Solution**:
- Add `alertType=dependency` to the fetch endpoint call list.
- Normalizer branch: `type=dependency`, `rule_id` from `rule.id`, location from `logicalLocations[0]` (path = manifest file), `metadata.package = { name, version, ecosystem }`, `metadata.cve` populated, `fingerprint = sha1(repoId|package.name|rule.id)`.
**Definition of Done**:
- Fixtures cover npm / maven / pip / nuget variants.
- Pest test asserts fingerprint stable across re-fetches when alert content unchanged.
**Relevant files**: [plugins/source/siem-source-azdo/](../plugins/source/siem-source-azdo/).

### S5 — AzDO source: secret alerts + on-demand enrichment
**Goal**: Secret alerts with `truncatedSecret`, `validationFingerprints`.
**Solution**:
- Normalizer branch: `type=secret`, `metadata.detector = state.detectionEngine.name`, `metadata.validationFingerprints = state.validationFingerprints`, `metadata.truncatedSecret` masked-display only (never logged).
- `enrichEvent` calls `GET /_apis/alert/repositories/{repoId}/alerts/{alertId}/instances` returning full occurrences; result merged into `metadata.occurrences[]`.
- UI (S15) triggers enrichment lazily when the secret detail tab opens.
**Definition of Done**:
- Pest tests against fixtures for secret alerts.
- Assertion that the `truncatedSecret` is never present in any log line (using `assertLogDoesntContain`).
**Relevant files**: [plugins/source/siem-source-azdo/](../plugins/source/siem-source-azdo/).

### S6 — AzDO source: `pushEventState`
**Goal**: Upstream state propagation (consumed by M3 Sync).
**Solution**:
- `PATCH /_apis/alert/repositories/{repoId}/alerts/{alertId}?api-version=7.1-preview.1` body `{ state, dismissalReason, dismissalMessage }`.
- Mapping: local `dismissed` → upstream `dismissed` with reason from `metadata.dismissalReason` (defaults to `falsePositive`); `resolved` → `fixed`; `open`/`in_progress` → `active`.
**Definition of Done**:
- Pest mock test verifies request shape.
- `pushEventState` never called outside the `PushEventStatesJob` (PHPStan rule or architecture test via `pestphp/pest-plugin-arch`).
**Relevant files**: [plugins/source/siem-source-azdo/](../plugins/source/siem-source-azdo/).

---

## Epic E4 — ASoC Source

### S7 — ASoC source: client + system/container fetch
**Goal**: Replicate the polished prior-iteration ASoC client.
**Solution**:
- `App\Sources\Asoc\AsocClient` via `OutboundHttpFactory`.
- Auth: `POST /api/v4/Account/ApiKeyLogin` with `{ KeyId, KeySecret }` → bearer token cached 55 min in `cache` driver under key `asoc.token`. On 401, evict cache and retry once.
- `GET /api/v4/Apps?$top=100&$skip=...` paginated.
- `GET /api/v4/Scans?$filter=AppId eq '<id>'` per system.
- Capabilities: `hasContainers=true, canUpdateState=true, canAddComments=true, supportedEventTypes=[vulnerability, dependency, secret, misconfiguration]`.
**Definition of Done**:
- Pest tests for token cache, 401 retry, pagination across two pages.
- Proxy honored (test injects fake proxy URL and asserts request routed through it).
**Relevant files**: [plugins/source/siem-source-asoc/src/asoc-client.js](../plugins/source/siem-source-asoc/src/asoc-client.js), [dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs](../dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs).

### S8 — ASoC source: typed issue fetch + normalization
**Goal**: Handle all ASoC issue variants: SAST, SCA, Secret, DAST/API, Misconfig.
**Solution**:
- `GET /api/v4/Issues/Application/{appId}?$filter=LastUpdated ge '{iso}'` for incremental fetch.
- Normalizer dispatches on `IssueType` + `Classification` + `Scanner`:
  - **SAST**: `type=vulnerability`, location from `SourceFile`+`Line`+`Method`, `metadata.cwe` from `AdditionalData`.
  - **SCA**: `type=dependency`, `metadata.package = { name, version }`, `metadata.cve = CveId`.
  - **Secret** (`Classification == "Secret Detection"`): `type=secret`, `metadata.fingerprint = Fingerprint`, `metadata.detector = Scanner`.
  - **DAST/API**: `type=vulnerability`, `metadata.api = Api`, `metadata.apiVulnName = ApiVulnName`, `location = Location`.
  - **Misconfig**: `type=misconfiguration`.
- Severity map: `Critical/High/Medium/Low/Informational` → lowercase enums.
- State map: `New/Open/Reopened → open`, `InProgress → in_progress`, `Fixed → resolved`, `Passed/Noise → dismissed`.
- Metadata: `issueTypeId`, `language`, `scanner`, `fixGroupId`, `cve`, `cwe`, `links[]` (NVD, CWE, source-file URL).
**Definition of Done**:
- Recorded fixtures committed for each of the 5 variants under `tests/Fixtures/Asoc/`.
- Pest tests parameterized over all 5 variants asserting normalized output.
**Relevant files**: [plugins/source/siem-source-asoc/src/asoc-normalizer.js](../plugins/source/siem-source-asoc/src/asoc-normalizer.js), [dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCNormalizer.cs](../dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCNormalizer.cs).

### S9 — ASoC source: `pushEventState`
**Goal**: Update issue status + add comment upstream.
**Solution**:
- `PUT /api/v4/Issues/Application/{appId}/Update` body `{ Status, Comment, odataFilter: "Id eq <sourceEventId>" }`.
- `appId` resolved from the local `software_system_id` join (no separate cache).
- Status map: local `open → New`, `in_progress → InProgress`, `resolved → Fixed`, `dismissed → Noise` (with comment carrying the dismissal reason).
**Definition of Done**:
- Pest mock test verifying request body shape and odataFilter quoting.
**Relevant files**: [plugins/source/siem-source-asoc/](../plugins/source/siem-source-asoc/).

### S10 — ASoC source: focused remediation article enrichment
**Goal**: Fetch and cache the most precise remediation article for each event type.
**Context**: ASoC's `Remediation` field is often empty; the company has a separate articles endpoint. Some types (API/DAST) have per-`ApiVulnName` sub-articles linked from a general page.
**Solution**:
- New table `articles` (id, issue_type_id, language, api_vuln_name nullable, fetched_at, markdown longtext). Unique `(issue_type_id, language, api_vuln_name)`.
- `enrichEvent` for ASoC:
  1. Build URL `GET /api/v4/Reports/Article/?issuetype={IssueTypeId}&language={Language}&cveId={CveId}`.
  2. If event has `ApiVulnName`, parse the general article HTML using `symfony/dom-crawler`, find `<div id="apiLinks">` and match the anchor whose text equals `ApiVulnName`. Fetch that focused URL.
  3. Sanitize HTML: strip `<script>`, `<style>`, `<img>`, inline event handlers using `symfony/dom-crawler` + node removal (no regex).
  4. Convert HTML → Markdown via `league/html-to-markdown`.
  5. Cache result; TTL = 7 days.
- `security_events.remediation` populated from cache on first enrichment.
**Definition of Done**:
- Pest tests using committed HTML fixtures for: SAST general, SCA with CveId, DAST/API with focused `ApiVulnName`.
- Cache-hit test: second call does not invoke HTTP.
- Sanitization test: script tag removed.
**Relevant files**: [dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs](../dotnet/src/AppSecScout.Core/Sources/ASoC/ASoCClient.cs) (`BuildArticleUrl`, `GetFocusedArticleUrlAsync`, `ConvertHtmlToMarkdown`).

---

## Epic E5 — Detectify Source

### S11 — Detectify source
**Goal**: Fetch findings (no container concept).
**Solution**:
- `App\Sources\Detectify\DetectifyClient` with `Authorization: <api-key>` header.
- Endpoints: `GET /v2/domains/`, `GET /v2/domains/{token}/findings/`.
- Normalizer: `asset_token` → system source id; `uuid` → event source id; severity from `severity`; state from `status`; `rule_id` from `cwe`; `remediation` from `definition.remediation`; `url` from `links.details_page`.
- `pushEventState`: `PATCH /v2/domains/{token}/findings/{uuid}/` body `{ status, note }`.
- Capabilities: `hasContainers=false, canUpdateState=true, canAddComments=true, supportedEventTypes=[vulnerability, misconfiguration]`.
**Definition of Done**:
- Pest tests using recorded fixtures from the prior iteration.
- `pushEventState` integration tested with mock.
**Relevant files**: [plugins/source/siem-source-detectify/](../plugins/source/siem-source-detectify/).

---

## Epic E6 — Sync Orchestrator

### S12 — Sync orchestrator + scheduling
**Goal**: Background job runs incremental fetch for each enabled source.
**Solution**:
- `App\Sync\FetchSourceJob implements ShouldBeUnique` with `uniqueId = "fetch-source:{sourceId}"` and `uniqueFor = 600`.
- For each source:
  1. Read `sync_runs` for last successful `synced_at`.
  2. Call `fetchSystems` → upsert; call `fetchContainers` per system → upsert.
  3. Call `fetchEvents(since)` → stream into `App\Sync\Upserter::upsert(EventDto $dto)`.
  4. Upserter matches on `(source_id, source_event_id)`: preserves `comments`, `metadata.local`, `is_dirty`, `pending_state`, `pending_comment`; refreshes everything else; sets `synced_at = now()`.
- `sync_runs` table (id, source_id, started_at, finished_at, status enum[running|success|failure], counts_json, error_message). Records per run.
- Scheduler `app/Console/Kernel.php` dispatches `FetchSourceJob` per enabled source every 30 minutes (configurable per source in `integration_settings`).
**Definition of Done**:
- Pest integration test with a fake source asserts: new events created, existing events updated, dirty flags preserved, sync_run row written for both success and failure paths.
- Manual smoke against real AzDO instance produces non-zero event count.

---

## Epic E7 — Reader UI

### S13 — Dashboard
**Goal**: Login landing page showing aggregate state.
**Solution**:
- Filament dashboard widgets:
  - `StatsOverviewWidget` — counts per severity, per state, total open.
  - `ChartWidget` — severity distribution (doughnut).
  - `TableWidget` — last 10 `sync_runs` with status + duration + counts.
- Cache-busted on `App\Events\SyncRunFinished`.
**Definition of Done**:
- Pest tests for widget data builders.
- Manual smoke after a full sync against fixtures.

### S14 — Alerts list page
**Goal**: Filament resource for `SecurityEvent` with rich filter/search/sort, persistent per-user.
**Solution**:
- Filters: severity (multi), state (multi), source (multi), software_system (searchable select), container (searchable select), type (multi), has-work-item (boolean), tag (multi).
- Search: title, description, `metadata->cveId`, `metadata->ruleId`, FULLTEXT fallback over `(title, description)`.
- Sort: severity desc (default), `last_seen_at` desc, `first_seen_at`.
- Filter state persisted per-user in `user_view_state` (user_id, view_id, payload_json). New migration in this story.
- Columns: severity pill (colored), state pill, source badge, tracker badge (M4), title (linked to detail), last_seen_at relative.
**Definition of Done**:
- Pest tests for each filter against a seeded fixture set.
- UI smoke for persistence across logouts.

### S15 — Alert detail page (type-specific sections)
**Goal**: Rich detail view rendering type-specific information.
**Solution**:
- Filament `ViewRecord` page with conditional sections:
  - Universal: severity/state/source pills, fingerprint, first/last seen, source link, system + container links.
  - `secret`: detector, validation status (from `metadata.validationFingerprints`), truncated value (masked badge `••••• (4 chars)`), occurrence count, "Load occurrences" button → triggers `enrichEvent` for AzDO.
  - `dependency`: package name+version, CVE link to NVD, CVSS (if present), fixed-in version.
  - `vulnerability` / `code_quality`: location with file path linked to `version_control_url`+line, code snippet rendered with syntax highlighting via `tempest/highlight`, CWE link, rule ID.
  - `misconfiguration` / `iac` / `posture`: resource type, recommendation, link to documentation.
- Remediation section: rendered Markdown via `league/commonmark` (sanitized via `league/commonmark` `HtmlFilterExtension`).
- Tabs: Comments (M3-S1), Audit history (read-only view of audit_logs filtered by subject), Work-item links (M4).
- Action "Reload from source" (M3-S6 gating).
**Definition of Done**:
- Pest tests for the type-section selector.
- Manual smoke for each of the 8 event types.

### S16 — Software system + container browsing
**Goal**: Browse alerts grouped by their physical structure.
**Solution**:
- Filament resource `SoftwareSystem` listing systems with aggregated counts (open + by severity).
- Detail page with tabs: Events (filtered to this system), Containers (relation manager), Linked systems (if member of a composite).
- `SecurityContainer` resource similarly with events tab.
**Definition of Done**:
- Pest tests for relation managers.
- Manual smoke.

---

## Epic E8 — Composite Software Systems

### S17 — Composite (linked) software systems
**Goal**: Admin can group N physical systems into one virtual system; alerts queries respect linkage.
**Context**: Some applications span multiple AzDO repos or ASoC apps; operators want one logical view.
**Solution**:
- Filament admin page `System Links` with CRUD on `software_system_links`:
  - Create: name + description.
  - Edit: drag-and-drop to add/reorder member systems (via Filament repeater with custom `reorderable`).
  - View: shows aggregated stats and a "View alerts" button.
- Eloquent scope `SecurityEvent::forVirtualSystem(int $linkId)` resolves to underlying physical IDs via `software_system_link_members`.
- Alerts list filter "system" extended to accept either physical or virtual system IDs.
**Definition of Done**:
- Pest tests for the query scope.
- Pest test for member uniqueness (cannot add same system twice to a link).
- Manual smoke for the drag-and-drop UI.
**Relevant files**: [changelog/20260205T075700Z-composite-system.md](../changelog/20260205T075700Z-composite-system.md).

---

## Definition of Done — Milestone M2

- All stories' DoDs met.
- A complete scheduled sync run against real AzDO, ASoC, Detectify instances populates the DB without errors.
- Reader role can browse the data from a fresh login.
- `vendor/bin/pint --test` clean; `vendor/bin/pest` green.
- `docker compose up` boots and the schedule worker is observed running `FetchSourceJob` instances.
