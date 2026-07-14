# Resume Work — Reassessment of Prior Findings (2026-07-14)

Not linked from anywhere in the codebase or docs. Purpose: pick this back up tomorrow. Produced by
re-investigating four previously-saved findings (B, C, D, E) against current code, the legacy
Node.js/. NET submodules under `./legacy-code`, and project docs — not just against docs as the
original findings were. Findings A were not part of this pass (owner already judged them cheap
doc/code fixes and out of scope for reassessment).

Housekeeping note: `./legacy-code` submodule was uninitialized at the start of this session; it has
now been initialized (`git submodule update --init --recursive`). It contains a stale self-referencing
nested submodule at `legacy-code/appsec-scout-2` (this repo referencing itself, recursively) — ignore
that path, it was not used for this investigation.

---

## Headline surprise: Finding E is already fixed

While re-investigating, we discovered finding **E (link-based configuration silently doing nothing)**
was already fully addressed by commit `6b76cff` ("Fix silent UX traps in tracker project link
resolution (#249)"), authored today, already on this branch/HEAD with a clean working tree. All three
sub-points (E1/E2/E3) are **NOT CONFIRMED as still-existing bugs** — verified fixed in code. The only
remaining work for E is **doc drift**: `docs/concepts/links-and-defaults.md` and `docs/concepts/triage.md`
still narrate the old, pre-fix behavior in places and should be corrected to match. See §E below for
exact locations.

---

## A. Finding B — Triage: staged changes that can never actually resolve

**Verdict: B1, B2, B3 all CONFIRMED.** All three are also already described, near-verbatim, in
`docs/concepts/triage.md` and `docs/concepts/sources-trackers-source-control.md` as *known, accepted
tradeoffs of the local-first design* — this is a documented gap the owner is now deciding whether to
close, not a silently-undiscovered bug. One genuinely new, undocumented bug was found in the process
(§A.4).

### B1 — Pending severity never clears
- No Source declares `canUpdateSeverity: true` (`app-laravel/app/Sources/AzDo/AzDoSource.php:46`,
  `.../Asoc/AsocSource.php:45`, `.../Detectify/DetectifySource.php:42` — all `false`).
- `PushEventStatesJob::handle()` (`app-laravel/app/Sync/PushEventStatesJob.php:86-94`) clears only
  `pending_state`/`pending_comment` on success, and recomputes `is_dirty` from
  `pending_severity !== null` — so a staged severity is a **permanent** dirty flag; nothing anywhere
  ever nulls `pending_severity`.
- The job's skip guard (`pending_state === null`, line 75) also means a **severity-only** change never
  even gets a push attempt.
- `SeverityChanger::change()` (`app-laravel/app/Triage/SeverityChanger.php:32-37`) is the only writer of
  `pending_severity`; no code path ever resets it.

### B2 — Standalone comment never clears
- Reachable today via `CommentsRelationManager`'s "Add comment" action
  (`app-laravel/app/Filament/Resources/SecurityEventResource/RelationManagers/CommentsRelationManager.php:62-102`)
  → `CommentManager::add()` (`app-laravel/app/Triage/CommentManager.php:17-40`), which creates an
  `EventComment` row and sets `is_dirty = true` but never touches `pending_state`.
- `PushEventStatesJob` skips any event with `pending_state === null` — a comment-only edit is always
  skipped and never clears `is_dirty`. Confirmed reproducible, not hypothetical.
- Clarification on "standalone comment": two distinct concepts exist —
  - `EventComment` (persistent per-event log, `upstream_comment_id` null = locally authored) — this is
    what B2 is about.
  - `pending_comment` (one-shot staging string on `security_events`, used only as justification text
    riding along a state/severity push).
  Even when a comment *does* ride along with a state/severity change, only the `pending_comment` string
  is pushed — the `EventComment` row created as a side effect is never itself marked synced or sent
  upstream. `pending_comment` is a one-shot string, not a queue of `EventComment` rows.

### B3 — SourceCapabilities never read by the Triage UI
- `grep` for `SourceCapabilities|canUpdateState|canUpdateSeverity|canAddComments` across `app-laravel/app`
  hits only inside `Sources/` itself — zero references from `Filament/**`, `Triage/**`, `Sync/**`.
- Every Triage action (change state, change severity, bulk state change, add comment) is gated purely by
  `alerts.edit`/`alerts.bulk-edit`, identically regardless of source
  (`SecurityEventResource.php:558,578,672`; `ViewSecurityEvent.php:44,53,107,126`;
  `CommentsRelationManager.php:65,76`). "Change severity" is shown and usable for every alert even though
  no shipped Source supports it — no warning surfaced.

### Per-source real upstream capability matrix (verified against `legacy-code/`)
Built from `legacy-code/legacy/appscan-client/lab/*.js` empirical test scripts,
`legacy-code/plugins/source/siem-source-{azdo,asoc,detectify}/src/*-client.js`, and
`legacy-code/legacy/appscan-client/doc/appscan-swagger-v4-patched.json`.

| Source | State push | Severity push | Standalone comment | Comment attached to state push |
|---|---|---|---|---|
| **AzDO** | Yes (`PATCH alerts/{id}`, `state`) | **No** — empirically confirmed: severity is server-computed/read-only, explicitly tested and rejected in `lab/azdo-14-test-alert-update-capabilities.js` / `lab/test-severity-update.js` | **No** — same lab file, Test 14 explicitly "❌ NOT SUPPORTED"; no thread/discussion API for alerts exists | Only as `dismissedComment`, only valid when transitioning to `Dismissed` (`azdo-client.js:499-508`) |
| **ASoC** | Yes (`PUT Issues/Application/{appId}/Update`, `Status`) | **Ambiguous** — swagger's `UpdateIssue` schema *lists* a writable `Severity` field, but the legacy plugin author's changelog and a dedicated (unexecuted) test script both treat it as unsupported. **Needs live-tenant verification before deciding**, don't assume settled either way. | No — `/Issues/{id}/Comments` is GET-only in swagger; no POST/create-comment operation exists | Yes — `Comment` rides the same `Status` update payload |
| **Detectify** | Yes (`POST vulnerabilities/uuid/{uuid}/{statusAction}/`) | No — no severity-set endpoint exists anywhere in the client | No | **No — and this is a live bug, see §A.4** |

General structural fact worth stating plainly in the new doc: **none of the three real upstream APIs
expose a genuine "standalone comment independent of a status change" endpoint.** This is an upstream
constraint, not an appsec-scout omission — B2 should not be framed as "we forgot to call a comment API."

### A.4 — New bug found (not part of the original finding, flag separately)
`app-laravel/app/Sources/Detectify/DetectifySource.php:43` declares `canAddComments: true`, but
`DetectifyClient::updateFindingStatus(string $domainToken, string $uuid, string $status, ?string $note = null)`
(`app-laravel/app/Sources/Detectify/DetectifyClient.php:60-63`) never uses `$note` in the request body —
it silently drops it. The legacy plugin author's own changelog
(`legacy-code/changelog/20260206T110000Z-detectify-source-plugin.md:89`) explicitly notes
"`canAddComments: false` (legacy: Detectify does **not** support comments on status changes)." Current
code's capability declaration is simply wrong, independent of the B1-B3 design question — worth fixing
regardless of what's decided about B1-B3.

### Remediation options for B1-B3 (no code written; local-first-preserving)
1. **Clear `pending_severity` staleness independent of state push.** When a Source doesn't support
   severity push, treat "staged but unpushable" as a terminal local-only state — stop flagging it as
   "pending sync" (while still keeping the staged value visible/auditable). Tradeoff: needs a policy
   call on when "unsupported" should silently stop counting as pending vs. hide a stuck item.
2. **Give standalone comments their own push path or their own status**, decoupled from `pending_state`
   — either push unpushed `EventComment` rows independently per capable Source, or stop marking
   `is_dirty=true` for comment-only adds when the Source can never push standalone. Tradeoff: former is
   more correct but adds a second push mechanism per Source; latter is simpler but changes current
   "pending sync" semantics.
3. **Make the Triage UI capability-aware** — read `Source::capabilities()` when rendering
   `changeSeverity`/`addComment`/pending-sync badges; disable or annotate ("this will stay local — X
   doesn't support pushing severity") instead of accepting the edit silently. Purely additive, closes B3
   without touching the B1/B2 data model. Tradeoff: decide whether an unsupported action should still be
   *offered* (useful for local record-keeping) or hidden (risks operators thinking the feature doesn't
   exist).
4. Fix the Detectify `canAddComments` bug (§A.4) regardless of which B1-B3 direction is chosen.

### For the new `docs/concepts/upstream-source-capabilities.md`
- Full per-source, per-operation matrix above, with the ASoC severity ambiguity flagged as "needs
  live-tenant verification," and the Detectify comment bug called out prominently.
- State the general fact: no upstream source supports a truly standalone comment API — this is a
  structural constraint on all three sources, not a local design gap.
- Reference `SourceCapabilities` value object
  (`app-laravel/app/Sources/ValueObjects/SourceCapabilities.php:7-19`) and contract
  (`app-laravel/app/Sources/Contracts/Source.php:21`).
- This doc should supersede/extend, not duplicate, the capability material already in
  `docs/concepts/triage.md` ("Staging vs. Pushing a Change") and
  `docs/concepts/sources-trackers-source-control.md` ("Capability Matrices").

---

## B. Finding C — Permissions seeded but not enforced (triage:codesearch / assets:sync-azdo-projects)

**Verdict: C1 CONFIRMED. C2 CONFIRMED, with one correction** — there is no seeded permission label for
`assets:sync-azdo-projects` at all (checked the full `PERMISSIONS` list in
`RolePermissionSeeder.php` — no `assets.*` entry exists). The accurate C2 finding is simply "no RBAC
check exists for this command, full stop" — same as every other Artisan command in the app (all ~20
live as closures in `app-laravel/routes/console.php`, zero `Gate::`/`can(`/`authorize(`/`Permission::`
calls anywhere in that file). Both C1 and C2 are already documented as known/accepted in
`docs/concepts/triage.md:134-137,148` and `docs/concepts/sbom-and-static-analysis.md:148-150`.

### Current credential-resolution state (precise — this determines remaining work)

| Command | System-credential fallback | Explicit-param override |
|---|---|---|
| `triage:codesearch` | **Missing** in the wired path (exists, unused, in `RunCodesearchJob`) | Present — `{pat}` is a mandatory positional arg |
| `assets:sync-azdo-projects` | **Present** — forced via `SystemIntegrationRuntime` → `Vault::runAsOwner(null, ..., strict: true)` | **Missing** — no CLI option at all |

Each command already has exactly one of the two capabilities the owner wants; neither has both.

- `triage:codesearch` (`routes/console.php:81-116`): `{pat}` is required, always explicit;
  `CodesearchService::run()` (`app-laravel/app/Triage/CodesearchService.php:18-24`) takes `$pat` with no
  vault fallback. A parallel, **already-built but unwired** path exists:
  `App\Triage\RunCodesearchJob` (`app-laravel/app/Triage/RunCodesearchJob.php:21-27`) resolves the PAT
  strictly from the system vault before calling the same `CodesearchService::run()` — but nothing in
  production ever dispatches this job (only exercised in
  `tests/Feature/Triage/RunTriageJobsTest.php`). **Correction to CLAUDE.md's description**: the "web UI
  path" that auto-resolves the operator's PAT from the AzDO Repos system credential does **not exist in
  code today** — `docs/concepts/triage.md:136-137` already says as much ("exists but is only exercised
  in tests today; nothing in production dispatches it"). No Filament file references codesearch at all.
- `assets:sync-azdo-projects` (`routes/console.php:186-265`): no credential argument/option exists;
  resolution is already 100% system-vault, strict, automatic via `SystemIntegrationRuntime::runSource()`
  (`app-laravel/app/Integrations/SystemIntegrationRuntime.php:35-40`) → `Vault::runAsOwner`. Fails with a
  caught `RuntimeException` if the AzDO Source isn't configured.

### Reference pattern already used by `invoke-ops.ps1 -SbomScan`/`-StaticAnalysis`
From `scripts/invoke-ops.ps1`:
1. **Explicit override**: if `-Credential (Get-Credential)` is passed, its password is used directly via
   `$env:AZDO_PAT` (lines 354, 379) — no vault lookup.
2. **System-credential fallback**: if omitted, `Get-SystemVaultCredential -Key 'azdo-repos.pat'`
   (lines 216-233) shells out to `docker compose exec -T app php artisan credentials:system:get
   azdo-repos.pat` — an Artisan command (`routes/console.php:627-667`) that reads the vault strictly at
   system scope (`Vault::get($key, null, true)`), same primitive `RunCodesearchJob` and
   `SystemIntegrationRuntime` already use.
3. If neither is available, no explicit fail-fast check exists in the PowerShell layer — failure surfaces
   downstream.

This is exactly the dual "explicit-or-system, both supported" pattern the owner wants both Artisan
commands to match — and confirms the primitive to reuse
(`Vault::get($key, null, true)` for strict system-scope lookup) already exists and is used in three
different places today.

### Remediation options
- **Option A — bring both commands up to the pattern in place.** Make `triage:codesearch`'s `{pat}`
  optional, falling back to `$vault->get('azdo-repos.pat', null, true)` (literally the same call already
  implemented, unused, in `RunCodesearchJob` — could be inlined/reused); add an optional
  `--pat=`/`--organization=` override to `assets:sync-azdo-projects`. Remove the
  `triage.run-codesearch` permission and its role assignments from `RolePermissionSeeder.php`; update
  `docs/concepts/triage.md:134,148` and `tests/Feature/Auth/RolePermissionTest.php:24` accordingly.
  Smallest diff; keeps both as Artisan commands (same trust boundary as every other Artisan command);
  doesn't resolve the owner's "some commands in Ops, some in Artisan" discomfort.
- **Option B — move both into `invoke-ops.ps1`/the `ops` profile**, deprecating/removing the Artisan
  commands (or keeping them as internal-only, invoked by the PS wrapper). Reuses
  `Get-SystemVaultCredential`/`-Credential` verbatim. Achieves full consistency but is a larger diff
  (new parameter sets, help text, doc rewrites in `docs/concepts/triage.md` and
  `docs/concepts/sbom-and-static-analysis.md`) and changes the day-to-day operator command
  (`docker compose exec app php artisan triage:codesearch ...` → `.\invoke-ops.ps1 -CodeSearch ...`).
- **Option C — hybrid/staged**: do only the credential-resolution parity fix + permission-seeder cleanup
  now (Option A), and treat "relocate commands between Ops and Artisan" as a separate, later story — per
  CLAUDE.md's story-splitting rule, these are arguably two independent changes.

---

## C. Finding D — No lifecycle management for locally-scanned data

**Verdict: D1, D2, D3 all CONFIRMED.** All three are also already explicitly documented as known,
accepted facts in `docs/concepts/sbom-and-static-analysis.md` and `docs/concepts/asset-system-container-alert.md`
— a documented gap, not an undiscovered one. **No legacy precedent exists** for D1 — checked
`legacy-code/core/src/{storage,sync}`, `legacy-code/dotnet*/src/AppSecScout.Core`, and the whole legacy
tree for `trivy|sarif|cyclonedx|sbom` — zero matches. The Node TUI and both .NET rewrites were
exclusively upstream-alert-triage and tracker-reconciliation tools; local SBOM/static-analysis scanning
is a wholly new-to-appsec-scout-2 concept with nothing to borrow from.

### D1 — No staleness/cleanup mechanism
- `AttachmentIngestionService::ingestSbom()`/`ingestFindings()`
  (`app-laravel/app/Assets/AttachmentIngestionService.php:54-78,80-112`) both `firstOrNew()` keyed on a
  natural key, then bump only `last_seen_at`; `first_seen_at` is set once on create. **No code anywhere
  diffs "what existed before this scan" against "what this scan returned"** — no mark-and-sweep, no
  `whereNotIn(...)->update(...)`, no delete of vanished rows.
- Migrations confirm no staleness column exists on either `local_findings` or `software_components`
  tables — no `status`, `is_stale`, `resolved_at`, or soft-delete trait.
- `docs/concepts/sbom-and-static-analysis.md:128-133` states this verbatim already.

### D2 — Fully read-only in Filament
- `LocalFindingResource.php` and `SoftwareComponentResource.php` both register only `index`/`view` pages
  (no `create`/`edit`), both have empty `form()` bodies, no policy classes exist for either model, and
  neither table defines any row/bulk mutating actions — only a read-only "Download findings"/"Download
  SBOM" link on the relation managers. Confirmed via grep: no "dismiss"/"suppress"/"ignore" concept
  exists anywhere near these two models.
- `docs/concepts/asset-system-container-alert.md:150-156,229` states this verbatim already.

### D3 — SecurityEventCorrelator has no undo path
- `SecurityEventCorrelator::correlate()` (`app-laravel/app/Assets/SecurityEventCorrelator.php:33-51`)
  only ever **sets** `correlated_security_event_id` on a match; no other code path anywhere writes to
  that column (no uncorrelate service, no listener clearing it on delete/dismiss of the linked alert, no
  Filament action to unlink) — a direct consequence of D2's fully-read-only resource.
- Notably, this is the **only** one of the app's four automatic-linking mechanisms marked
  "Reversible? No" in `docs/concepts/automated-discovery.md:33` — the other three (Asset auto-link,
  TrackerProjectLink auto-learn, WorkItemLink reconciliation) all already have an unlink/detach/edit path
  in the app today, establishing a precedent pattern to follow rather than invent.

### Remediation options
- **D1 — staleness detection**, three options: (1) mark-and-sweep per scan run scoped to owner — snapshot
  the natural-key set touched this run, flag/mark anything previously present but now absent (new column
  + migration, portable Eloquent diff query, no raw SQL); most consistent with "re-scanning is the local
  source-of-truth refresh." (2) time-based inference from `last_seen_at` vs. last scan timestamp — no
  schema change, cheaper, but weaker (can't distinguish "genuinely fixed" from "this run's scan skipped
  that ecosystem/repo," which the docs note already happens non-fatally). (3) explicit scheduled
  archival job that soft-deletes/resolves records untouched for N days — more infrastructure, cleaner
  audit-log fit as its own nameable action. No conflict with "upstream sources are read-only except when
  Sync explicitly pushes" — that principle governs upstream *Source* data; LocalFinding/SoftwareComponent
  have no upstream Source at all, so local mutation (including automated sweep) doesn't fall under that
  restriction.
- **D2 — Filament actions**: add a **"Dismiss"/"Suppress" action** (not full CRUD) — new
  `dismissed_at`/`dismissed_by` pair, `requiresConfirmation()` per CLAUDE.md convention, AuditLog entry;
  preserves "these are scan output, not manually authored records." Full CRUD is explicitly the wrong
  direction — these are parsed scan artifacts and free-editing them would drift from what Trivy/SARIF
  actually reported and could break re-scan upsert matching on natural keys. A bulk-only dismiss is
  cheaper but denies the single-item correction workflow the docs imply is missing; a combined per-row +
  bulk dismiss (via `ActionGroup::make([...])` per CLAUDE.md convention) is the natural fit.
- **D3 — unlink correlation**: mirror the **existing** `ReconciliationService`/`WorkItemLink` "Unlink"
  action pattern (same codebase, already reviewed) — clear `correlated_security_event_id`/
  `correlation_method`, `requiresConfirmation()`, audit-logged. Lower-risk than inventing a new pattern.
  Secondary options: a "re-correlate" action (re-run the correlator for one finding — doesn't help if the
  heuristic itself is wrong), or a manual "correlate to..." picker (highest operator value, most work,
  arguably scope creep beyond D3 narrowly).
- **Sequencing note**: D3's fix cannot ship without D2's scaffolding (Policy class, permission gate,
  `requiresConfirmation()`, AuditLog wiring) — building the D2 dismiss action and the D3 unlink action
  together is the most efficient path since both need the same missing infrastructure.

---

## D. Finding E — Link-based configuration that silently does nothing (ALREADY FIXED)

**Verdict: E1, E2, E3 all fixed by commit `6b76cff`** ("Fix silent UX traps in tracker project link
resolution (#249)", authored today, `havelangep@gmail.com`), already on this branch/HEAD, clean working
tree. Investigation reflects the **post-fix** state.

### E1 — Asset-level TrackerProjectLink unreachable
- Fixed by **removing** `TrackerProjectLinksRelationManager` registration from
  `SoftwareAssetResource::getRelations()` (`app-laravel/app/Filament/Resources/SoftwareAssetResource.php`)
  — confirmed via `git show 6b76cff`. The relation manager remains correctly registered at System and
  Container level (`SoftwareSystemResource.php:203`, `SecurityContainerResource.php:166`).
- Root cause confirmed unchanged: `SecurityEvent` has no relation to `SoftwareAsset`, direct or indirect
  (only `softwareSystem()` and `container()`). Chosen fix (hide the option where it can't work) rather
  than wiring a new resolution path — consistent with `docs/concepts/asset-system-container-alert.md`
  describing System as "the true mandatory anchor" and Asset as having "no fields of its own beyond a
  label and description."
- Any pre-fix rows created at Asset level are untouched in the DB (relation + cascade-delete still
  exist on the model) but are now unreachable/invisible in the UI going forward.
- **Remaining doc drift**: `docs/concepts/links-and-defaults.md:27-34` still claims all three levels get
  the relation manager and is "genuinely usable at three levels" — now false, needs updating.

### E2 — Multiple `is_default` links silently fall through
- DB-level gap is real and **unchanged**: `2026_05_29_000100_create_tracker_project_links_table.php:25`
  only uniquely constrains `(owner_type, owner_id, tracker_id, project_key)` — nothing prevents two rows
  at the same level+tracker both having `is_default=true`. No model observer enforces it either.
- But the **operator-facing symptom is fixed**: `TrackerProjectDefaultResolver::resolveFromLinks()`
  (`app-laravel/app/Trackers/Defaults/TrackerProjectDefaultResolver.php:218-282`) now detects ambiguity
  (`count() > 1 && defaults->count() !== 1`) and returns an explicit warning message, threaded through
  `TrackerProjectDefaultResolution::withAmbiguityWarning()` and rendered as a
  `Placeholder::make('tracker_ambiguity_notice')` in `WorkItemFormOptions.php` (both create and link
  schemas) — surfaced even when a lower level still resolves a usable default (explicitly intentional
  per code comment).
- **Not done**: a DB/unique-constraint-equivalent enforcing single-default-per-level+tracker — still open
  if stricter enforcement is wanted later (would need an observer/validation rule, since MySQL/SQLite
  don't support filtered unique indexes directly, per CLAUDE.md's DB-portability rules).

### E3 — "Find existing work items" more conservative than the service
- Fixed: `ViewSecurityEvent::dispatchReconcileEvent()`
  (`app-laravel/app/Filament/Resources/SecurityEventResource/Pages/ViewSecurityEvent.php:275-314`) still
  checks `hasApplicableTrackerMappings()` but now only to decide whether to show a **warning**
  notification ("Searching every configured tracker project instead of a scoped one...") — it then
  **unconditionally proceeds** to call `ReconciliationService::reconcileEvent()` regardless. Confirmed
  via `git show 6b76cff`: the prior `return true;` that stopped execution right after the notification
  was deleted, and `.info()` became `.warning()` with the explanatory body.
- **Remaining doc drift**: `docs/concepts/links-and-defaults.md:82-99` and `docs/concepts/triage.md:114-117`
  still describe the old blocking behavior ("shows an info notification... and stops... never lets the
  service's own broader fallback kick in") — needs rewriting to match warn-then-proceed.

### Remaining work for E (doc-only)
1. Update `docs/concepts/links-and-defaults.md`: correct the "three levels" claim (§E1), correct the
   "UI/service mismatch" section (§E3), and optionally note the still-open DB-constraint gap (§E2).
2. Update `docs/concepts/triage.md:114-117` to match the new warn-then-proceed reconciliation behavior.
3. Decide (separately, low priority) whether to add a DB-level or observer-level uniqueness rule for
   `is_default` per level+tracker, or leave the warning-only fix as sufficient.

---

## Suggested next steps (for tomorrow)

1. **Decide E's doc-only follow-up** — cheapest item, just needs `links-and-defaults.md` and
   `triage.md` corrections to match already-shipped code. Optionally decide on the E2 DB-constraint
   question.
2. **Decide B1-B3 direction** — pick one of the three remediation option sets in §A, and separately
   approve/schedule the Detectify `canAddComments` bug fix (§A.4) regardless. Also greenlight (or not)
   writing `docs/concepts/upstream-source-capabilities.md` using the matrix already compiled in §A.
3. **Decide C's direction** — Option A (credential parity + permission cleanup, small) vs. Option B
   (move commands into `invoke-ops.ps1`, larger, resolves the Ops/Artisan inconsistency) vs. Option C
   (do A now, defer the relocation decision). Note the reusable primitive already exists three times
   in the codebase (`Vault::get($key, null, true)`).
4. **Decide D's direction** — likely sequence: build the D2 dismiss-action scaffolding (Policy class +
   permission + `requiresConfirmation()` + AuditLog) together with the D3 unlink action, since D3 depends
   on D2's infrastructure; decide D1's staleness-detection approach (mark-and-sweep recommended) as a
   separate, schema-touching story per CLAUDE.md's story-splitting rule.
