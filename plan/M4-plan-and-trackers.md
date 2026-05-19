# M4 — Plan role + Jira/GitHub trackers

**Goal**: Plan operators create remediation work items in Jira Cloud or GitHub Issues from one alert or a grouped set of alerts. A scheduled job refreshes work-item state/title back into the local DB. Per-user and system-level credentials are managed via UI.

**Outcome**: A Plan operator selects 12 secret-scanning alerts of the same kind, creates a single Jira "Bug" with a rich grouped description, links all 12 alerts to it; later the Jira ticket is closed → AppSec Scout reflects the cached state badge automatically, but the alerts remain pending until a Sync operator decides to dismiss them upstream.

---

## Epic E1 — Tracker Plugin Contract

### S1 — Tracker contract + registry
**Goal**: PHP interfaces describing what a tracker plugin must implement.
**Solution**:
- Interface `App\Trackers\Contracts\Tracker`:
  - `id(): string`, `displayName(): string`
  - `capabilities(): TrackerCapabilities`
  - `requiredCredentialKeys(): array<string>`
  - `testConnection(): TestResult`
  - `fetchProjects(): iterable<ProjectDto>`
  - `fetchItemTypes(string $projectKey): iterable<string>`
  - `fetchAssigneeCandidates(string $projectKey, string $query): iterable<UserDto>`
  - `createWorkItem(CreateWorkItemRequest $req): WorkItemDto`
  - `getWorkItem(string $workItemKey): ?WorkItemDto`
  - `updateWorkItem(string $workItemKey, UpdateWorkItemRequest $req): WorkItemDto`
  - `searchWorkItems(string $projectKey, string $query, int $limit = 20): iterable<WorkItemDto>`
- `TrackerCapabilities` value object: `supportsLabels`, `supportsPriority`, `supportsAssignee`, `supportsParent`, `supportedItemTypes[]`, `maxDescriptionBytes`.
- `App\Trackers\Registry` collects implementations tagged `appsec-scout.tracker`.
**Definition of Done**:
- Parameterized Pest contract test against all registered trackers.

---

## Epic E2 — Description Generation

### S2 — Markdown → ADF converter (Jira)
**Goal**: Convert markdown descriptions to Atlassian Document Format.
**Context**: No battle-tested PHP library exists; we port the prior-iteration converter, using a proper Markdown AST (no regex parsing).
**Solution**:
- `App\Trackers\Jira\MarkdownToAdf::convert(string $markdown): array`.
- Uses `league/commonmark` to parse markdown into an AST node tree, then walks the tree producing ADF JSON:
  - Block nodes: paragraph, heading (level 1–6), bullet_list, ordered_list, list_item, blockquote, code_block, hr, table, table_row, table_cell.
  - Inline marks: strong, em, code, link.
- 16 KB byte cap applied on the input markdown before conversion (binary-search truncation by character boundary, with a "…(truncated)" marker appended).
- Unsupported nodes degrade to a paragraph containing the raw text.
**Definition of Done**:
- Pest tests for every supported node type (input md fixture → expected ADF JSON fixture).
- Pest snapshot test on a real 10-event grouped description.
- Property test: round-trip stability (same input twice produces identical output).
**Relevant files**: [plugins/tracker/siem-tracker-jira/src/markdown-to-adf.js](../plugins/tracker/siem-tracker-jira/src/markdown-to-adf.js).

### S3 — Description builder (single + grouped)
**Goal**: Generate the markdown body of work items.
**Solution**:
- `App\Trackers\DescriptionBuilder`:
  - `buildSingle(SecurityEvent $event): string`
  - `buildGrouped(iterable<SecurityEvent> $events): string`
  - `buildTitle(SecurityEvent $event): string`
  - `buildGroupedTitle(iterable<SecurityEvent> $events): string`
- Grouped layout (matches prior iteration):
  1. Severity summary table at the top: `| Severity | Count |`.
  2. Group events by `type`, sort groups by max severity desc.
  3. Per group: H2 heading with type name; shared description + remediation rendered once; occurrences listed compactly (`- {systemName}/{containerName} {filePath}:{line} ([alert](url))`).
- Strict 16 KB byte cap applied at the end via binary-search truncation on a paragraph boundary.
**Definition of Done**:
- Pest snapshot tests for single + grouped fixtures (3 types × multiple events).
- Length cap test asserts ≤16 KB even with 50 events.
**Relevant files**: [core/src/work-items/description-builder.js](../core/src/work-items/description-builder.js).

---

## Epic E3 — Jira Tracker

### S4 — Jira tracker
**Goal**: Implement `Tracker` for Jira Cloud.
**Solution**:
- `App\Trackers\Jira\JiraClient` via `OutboundHttpFactory` with Basic auth (`email:apiToken`).
- Endpoints (REST API v3):
  - `GET /rest/api/3/myself` (testConnection)
  - `GET /rest/api/3/project/search`
  - `GET /rest/api/3/issue/createmeta?projectKeys={key}&expand=projects.issuetypes`
  - `POST /rest/api/3/issue` body `{ fields: { project, summary, description (ADF), issuetype, labels, priority, assignee, parent } }`
  - `GET /rest/api/3/issue/{key}?fields=summary,status,labels,priority,assignee,parent`
  - `PUT /rest/api/3/issue/{key}` (editIssue)
  - `POST /rest/api/3/issue/{key}/transitions` (doTransition with `id`)
  - `GET /rest/api/3/issue/{key}/transitions`
  - `GET /rest/api/3/search?jql=...`
- Capabilities: `supportsLabels=true, supportsPriority=true, supportsAssignee=true, supportsParent=true, supportedItemTypes=[Bug,Task,Story,Epic], maxDescriptionBytes=16384`.
- `WorkItemDto.url = "{host}/browse/{key}"`.
- `updateWorkItem` separately handles state transitions (transition by id resolved from `getTransitions`).
**Definition of Done**:
- Pest tests against recorded fixtures for create / get / transition.
- Test for parent linking via `fields.parent.key`.
- Test for label set including `security`, `appsec-scout`, source id, severity, type.
**Relevant files**: [plugins/tracker/siem-tracker-jira/src/jira-tracker.js](../plugins/tracker/siem-tracker-jira/src/jira-tracker.js).

---

## Epic E4 — GitHub Tracker

### S5 — GitHub tracker
**Goal**: Implement `Tracker` for GitHub Issues.
**Solution**:
- `App\Trackers\GitHub\GitHubClient` via `OutboundHttpFactory` with `Authorization: Bearer <PAT>`.
- Endpoints (REST v3):
  - `GET /user` (testConnection)
  - `GET /user/repos?affiliation=owner,collaborator,organization_member&per_page=100` (paginated; treats each repo as a project, key = `owner/repo`)
  - `POST /repos/{owner}/{repo}/issues` body `{ title, body, labels, assignees }`
  - `GET /repos/{owner}/{repo}/issues/{number}`
  - `PATCH /repos/{owner}/{repo}/issues/{number}` body `{ state, state_reason, title, body, labels, assignees }`
  - `GET /search/issues?q=repo:{owner}/{repo}+...`
- Work item key format: `owner/repo#N`.
- Description stays as Markdown (no conversion).
- Parent linking: prepend `Parent: {owner}/{repo}#{N}\n\n` to body.
- State map: local `open/in_progress` → GitHub `open`; local `resolved` → `closed` (state_reason=completed); local `dismissed` → `closed` (state_reason=not_planned).
- Capabilities: `supportsLabels=true, supportsPriority=false, supportsAssignee=true, supportsParent=false (via body prefix only), supportedItemTypes=[issue], maxDescriptionBytes=65536`.
**Definition of Done**:
- Pest tests for create / get / update.
- Test that `state_reason=not_planned` produces `work_item_state = "Closed (not planned)"` cached on the link row.
**Relevant files**: [plugins/tracker/siem-tracker-github/src/github-tracker.js](../plugins/tracker/siem-tracker-github/src/github-tracker.js).

---

## Epic E5 — Work-Item Persistence + Creation

### S6 — Work-item links model
**Goal**: Persist alert↔work-item connections.
**Solution**:
- Migration `work_item_links` (id, event_id FK, tracker_id varchar, work_item_id varchar, work_item_url, work_item_title, work_item_state varchar, created_at, synced_at, created_by_user_id FK).
- Unique `(event_id, tracker_id, work_item_id)`.
- N-to-1 relation: multiple events may share the same `(tracker_id, work_item_id)` (grouped work item).
- Eloquent model `WorkItemLink` with relations to `SecurityEvent` and a soft pseudo-relation grouping by `(tracker_id, work_item_id)`.
**Definition of Done**:
- Migration applies.
- Pest tests for cascading delete when event removed.
- Pest test for the grouping pseudo-relation.

### S7 — Plan: create single work item
**Goal**: From alert detail page, create a tracker work item linked to this alert.
**Solution**:
- Filament action "Create work item" on detail page with form:
  - `tracker` (radio of enabled trackers)
  - `project` (searchable select populated from `fetchProjects` cached 1h)
  - `item_type` (dropdown from `fetchItemTypes`)
  - `priority` (shown only if capability supports it)
  - `labels` (multi-select with presets: `security`, `appsec-scout`, source id, severity, type, plus free entry)
  - `assignee` (searchable select via `fetchAssigneeCandidates`)
  - `parent` (optional searchable select via `searchWorkItems`)
- Submit dispatches `App\Trackers\CreateWorkItemJob`:
  1. Build title + description via `DescriptionBuilder::buildSingle`.
  2. Call `Tracker::createWorkItem`.
  3. Persist one `WorkItemLink` row.
  4. Audit `recordWorkItemCreated`.
- Authorization: `work-items.create` (Plan+).
**Definition of Done**:
- Pest integration test with fake tracker; one event → one tracker call → one link row.
- UI smoke.

### S8 — Plan: create grouped work item
**Goal**: Bulk-create one work item linking N selected alerts.
**Solution**:
- Filament bulk action on alerts list "Create grouped work item":
  - Same form as S7.
  - Description built via `DescriptionBuilder::buildGrouped`.
  - Single `Tracker::createWorkItem` call; one `WorkItemLink` row per selected event (all sharing `(tracker_id, work_item_id)`).
- Audit `recordWorkItemCreated` with `payload.event_ids = [...]` and `payload.grouped = true`.
**Definition of Done**:
- Pest integration test with 5 events of mixed types: assert one tracker call and 5 link rows.
- Pest snapshot test on the generated grouped description.

### S9 — Plan: link / unlink existing work item
**Goal**: Search a tracker for an existing item and link it to one or more alerts.
**Solution**:
- Filament action "Link existing" on alert detail page + bulk action on alerts list:
  - Form: `tracker` (radio), `project`, `query` (free-text → `searchWorkItems`), `selected_work_item` (radio from results).
  - On submit: persist link row(s); audit `recordWorkItemLinked`.
- Filament action "Unlink" on the work-item-links tab: removes link row; audit `recordWorkItemUnlinked`.
**Definition of Done**:
- Pest tests for both directions.

---

## Epic E6 — Tracker State Refresh

### S10 — Tracker refresh job
**Goal**: Pull current state and title of all linked work items into local DB.
**Context**: User decision — refresh is **read-only mirroring**. It updates the cached badge displayed in AppSec Scout but does **not** mutate the alert's state. A Sync operator decides whether the new tracker state implies an upstream alert change.
**Solution**:
- `App\Trackers\RefreshWorkItemsJob` scheduled every 30 minutes (configurable per tracker in `integration_settings`).
- Iterates `work_item_links` grouped by `tracker_id`.
- For each unique `(tracker_id, work_item_id)`, calls `Tracker::getWorkItem` once and updates all link rows sharing that key with `work_item_title`, `work_item_state`, `synced_at`.
- Rate-limit aware: tracker clients expose `rateLimitDelay` and the job sleeps accordingly.
- When `work_item_state` changes, audit `recordTrackerStateChanged` (no other side effects).
**Definition of Done**:
- Pest integration test where fake tracker returns a new state → link row updated; audit row written; alert state untouched.
- Pest test asserts deduplication: 5 events sharing one work item → 1 tracker GET call.

---

## Epic E7 — Credential Management UI

### S11 — Profile UI: user PATs
**Goal**: Each user manages their own PATs for sources and trackers.
**Solution**:
- Filament profile page `/profile/integrations`.
- For each enabled source/tracker, render a card with:
  - Fields from `requiredCredentialKeys()`.
  - Description field.
  - "Test connection" button → calls `Source::testConnection` / `Tracker::testConnection`; updates `last_tested_at`/`last_tested_ok`/`last_tested_error`.
- Persist via `App\Credentials\Vault::set($key, $userId, $value)`.
- Values masked after save: input becomes `••••••••` placeholder; replacing requires explicit "Replace value" toggle.
**Definition of Done**:
- Pest tests for save + test action.
- Pest test asserts value masked in UI response after save.
- Audit row on every change.

### S12 — Admin UI: system PATs
**Goal**: Admin manages credentials used by background jobs.
**Solution**:
- Filament page `Admin → System credentials` (Admin role only).
- Same form as S11 but with `owner_user_id = null`.
- Background jobs preference order (resolved in `App\Sync\CredentialResolver`):
  1. System PAT for the integration key.
  2. Fallback to a designated "service user" (configurable in `integration_settings.service_user_id`).
- Configurable from the Integrations admin page (M6-S2).
**Definition of Done**:
- Pest tests for the resolver preference logic.
- Audit row on every change.

---

## Definition of Done — Milestone M4

- Plan operator can create single + grouped work items against Jira and GitHub.
- Linked items refresh automatically; UI badges reflect live tracker state.
- Per-user and system credentials managed via UI; test action works for all integrations.
- `vendor/bin/pint --test` clean; `vendor/bin/pest` green.
- Manual smoke against real Jira and GitHub instances creates valid issues with correct labels/parents/descriptions.
