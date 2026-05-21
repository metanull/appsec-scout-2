# M3 — Triage + Sync roles

**Goal**: Triage operators edit alert state and add comments locally; Sync operators review pending changes and propagate them upstream.

**Outcome**: A Triage operator can mark an alert "dismissed (false positive)" with a justification, optionally in bulk; a Sync operator then reviews the queue and propagates the change back to AzDO/ASoC/Detectify.

---

## Epic E1 — Local Edits (Triage)

### S1 — Comments
**Goal**: Display upstream comments and allow local comments.
**Context**: Comments are part of the alert narrative; adding one marks the alert as dirty so Sync can choose to push it upstream.
**Solution**:
- Filament relation manager on the alert detail page listing `event_comments` chronologically.
- Upstream comments (where `upstream_comment_id != null`) shown read-only with a "From source" badge.
- Local comments (where `author_user_id == current user`) editable for 5 minutes after creation, then immutable.
- Creating a local comment:
  - Inserts `event_comments` row with `author_user_id` set.
  - Sets `security_events.is_dirty = true`.
  - Calls `Recorder::recordCommentAdded`.
**Definition of Done**:
- Pest tests for create/list/edit-window.
- Audit row produced on create.

### S2 — State edit (Triage)
**Goal**: Triage operator changes alert state.
**Context**: State changes remain the primary triage workflow and always require an operator justification that Sync can review and propagate upstream.
**Solution**:
- Filament action "Change state" on alert detail page + inline action on alerts list:
  - Form: `new_state` (enum dropdown), `comment` (required textarea, min 10 chars).
  - On submit:
    1. Set `pending_state = new_state`, `pending_comment = comment`, `is_dirty = true`.
    2. Insert local comment with body `[State change: <state>] <comment>`.
    3. Audit `recordStateChange`.
- Authorization: requires `alerts.edit` permission (Triage+).
**Definition of Done**:
- Pest tests for happy path, comment required, permission denied for Reader.
- UI smoke.

### S3 — Severity edit (Triage)
**Goal**: Triage operator changes alert severity locally using the same reviewed workflow as state changes.
**Context**: Some upstream sources support severity updates and some do not, but the local database must preserve operator-requested severity changes so Sync can later decide whether to propagate or retain them locally.
**Solution**:
- Add local pending-severity support to `security_events` alongside the existing pending-state fields.
- Filament action "Change severity" on alert detail page + inline action on alerts list:
  - Form: `new_severity` (enum dropdown), `comment` (required textarea, min 10 chars).
  - On submit:
    1. Set `pending_severity = new_severity`, `pending_comment = comment`, `is_dirty = true`.
    2. Insert local comment with body `[Severity change: <severity>] <comment>`.
    3. Audit `recordSeverityChange`.
- Reuse a dedicated `App\Triage\SeverityChanger` service so the change is implemented once and can later be consumed by Sync.
- Authorization: requires `alerts.edit` permission (Triage+).
**Definition of Done**:
- Pest tests for happy path, comment required, and permission denied for Reader.
- Local event row preserves the current synced severity until a later sync accepts the pending severity.
- UI smoke.
- Relevant files: `E:\appsec-scout-2\legacy-code\core\src\models\security-event.js`, `E:\appsec-scout-2\legacy-code\dotnet\src\AppSecScout.Core\Models\`, `E:\appsec-scout-2\legacy-code\plugins\source\siem-source-asoc\src\asoc-normalizer.js`, `E:\appsec-scout-2\legacy-code\dotnet\src\AppSecScout.Core\Sources\ASoC\ASoCNormalizer.cs`

### S4 — Bulk triage on alerts list
**Goal**: Apply same state + comment to N selected alerts.
**Context**: Common pattern — closing multiple "false positive" findings of the same rule at once.
**Solution**:
- Filament bulk action "Change state (bulk)" with confirmation modal:
  - Form: `new_state`, `comment` (mandatory, single value applied to all).
  - On submit: iterate selected events in a single DB transaction, applying the same state+comment logic as S2.
  - Audit `recordBulkStateChange` with payload `{ event_ids, new_state, count }`.
- Reuses the same `App\Triage\StateChanger` service as S2 (single source of truth).
**Definition of Done**:
- Pest test selects 5 events, asserts all marked dirty with same pending state.
- Audit row contains the full list of event_ids.
- UI smoke.

---

## Epic E2 — Upstream Propagation (Sync)

### S5 — Pending-sync review page
**Goal**: Sync operator sees a queue of pending changes grouped by source.
**Context**: Sync is a deliberate, reviewed action — not an auto-push.
**Solution**:
- Filament page `/sync/pending` listing events where `is_dirty = true`, grouped by `source_id`, sorted by `updated_at` desc.
- Each row displays: current upstream `state` and `severity` (from last sync), `pending_state`, `pending_severity`, `pending_comment` preview, last editor, last edited at.
- Diff section shows current vs pending state and severity visually (color-coded).
- Rows with a pending severity change remain reviewable in this queue even when upstream severity propagation is not yet executed in M3.
- Bulk select + bulk action "Push to source".
- Authorization: requires `work-items.sync` and `sources.push-state` permissions (Sync+).
**Definition of Done**:
- Pest tests for the listing query and grouping.
- UI smoke with a mix of clean and dirty events.

### S6 — `PushEventStatesJob`
**Goal**: Queued job that pushes selected dirty events upstream.
**Solution**:
- `App\Sync\PushEventStatesJob` accepts `array $eventIds`. For each:
  1. Load event with source.
  2. Call `Source::pushEventState($event)`.
  3. On success:
     - Set `state = pending_state`; clear `pending_state`, `pending_comment`, `is_dirty`.
     - Set `synced_at = now()`.
     - Audit `recordSyncPush(success)`.
  4. On failure:
     - Leave dirty; increment `metadata.pushRetryCount` (cap 3).
     - Record error in `error_logs` and in `sync_runs` (running per push session).
     - Audit `recordSyncPush(failure, error)`.
     - On 3rd failure: stop retrying automatically; surface in pending-sync page with a "Last error" badge.
- In M3, `pending_severity` is preserved as local pending data and is not cleared by this job; severity propagation rules remain a later Sync refinement per source capability.
- Job is dispatched by the "Push to source" bulk action from S5.
- Throttled per source (1 concurrent job per source via `WithoutOverlapping`).
**Definition of Done**:
- Pest integration test with fake source returning success → dirty cleared, audit row, state synced.
- Pest test with fake source returning failure → dirty preserved, retry count incremented, error logged.
- Pest test with `pending_severity` set asserts the field is preserved after a successful state push.
- Pest test asserts retry stops at 3.

### S7 — Reload single event from source
**Goal**: Force re-fetch of one event from its source (when upstream changed externally).
**Context**: Operator may know that another team modified an alert directly in AzDO; this action refreshes the local copy without waiting for the scheduled sync.
**Solution**:
- Filament action "Reload from source" on alert detail page.
- Dispatches `App\Sync\RefetchEventJob` which calls `Source::fetchRawEvent($event)` and pipes the result through `Upserter` (same logic as S12 in M2).
- If the local copy has `is_dirty = true`, prompts the operator to confirm: "Local pending changes will be preserved; upstream metadata will be refreshed."
- Authorization: requires `work-items.sync` (Sync+).
**Definition of Done**:
- Pest tests for refresh flow.
- Pest test asserts dirty state + pending_state preserved across refresh.
- UI smoke.

---

## Definition of Done — Milestone M3

- Triage operator can change state, change severity, apply bulk state edits, and add comments.
- Sync operator can review pending changes and push them upstream via job.
- Failed pushes do not lose pending state; capped retry visible to operator.
- `vendor/bin/pint --test` clean; `vendor/bin/pest` green.
- Audit history visible on alert detail page (from M2-S15 tab) shows the full state-change + sync chain.
