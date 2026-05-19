# M5 — Defender for Cloud source + Triage Artisan commands

**Goal**: Add Microsoft Defender for Cloud as a fourth source (Service-Principal authenticated, read-only). Add three operator-facing Artisan commands (`triage:trivy`, `triage:bfg`, `triage:codesearch`) that execute pre-installed binaries safely from the single image, attaching results to alerts.

**Outcome**: Operators receive an additional stream of Code/Dependency/Secret/IaC/Posture alerts from Azure subscriptions. From the alert detail page (or CLI), they can launch on-demand triage tasks whose outputs (SARIF, BFG report, code-search JSON) are attached to the alert.

---

## Epic E1 — Defender for Cloud Source

### S1 — Defender ARG client (Service Principal auth)
**Goal**: OAuth2 client-credentials auth + Azure Resource Graph query infrastructure.
**Context**: Prior iterations supported interactive auth for the desktop app; the container must use a Service Principal. User decision: assume SP credentials are available.
**Solution**:
- `App\Sources\Defender\DefenderClient` via `OutboundHttpFactory`.
- Auth: `POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with form body `{ client_id, client_secret, scope=https://management.azure.com/.default, grant_type=client_credentials }`. Token cached until `expires_in - 60s` (via Laravel cache).
- ARG query: `POST https://management.azure.com/providers/Microsoft.ResourceGraph/resources?api-version=2022-10-01` body `{ subscriptions: [...], query: "<KQL>", options: { top: 1000, skipToken } }`.
- **Critical**: use lowercase `top` / `skipToken` (ARG v5 naming), not `$top` / `$skipToken` (legacy).
- `requiredCredentialKeys = ["defender.tenant_id", "defender.client_id", "defender.client_secret", "defender.subscription_ids"]`.
- `testConnection`: ARG query `securityresources | limit 1`.
**Definition of Done**:
- Pest test for token caching (one call → cached, second call → no HTTP).
- Pest test for token re-fetch on expiry.
- Pest test for paginated ARG queries via `skipToken`.
**Relevant files**: [plugins/source/siem-source-defender/](../plugins/source/siem-source-defender/), [dotnet/src/AppSecScout.Core/Sources/Defender/](../dotnet/src/AppSecScout.Core/Sources/Defender/).

### S2 — Defender: sub-assessments → Code / Dependency / Secret
**Goal**: Fetch DevOps-category sub-assessments and normalize into the three primary types.
**Solution**:
- KQL query: `securityresources | where type == "microsoft.security/assessments/subassessments" | where properties.additionalData.assessedResourceType in~ ("CodeRepository","Container","CloudArtifact") | extend categ = tostring(properties.category) | project id, name, properties`.
- Normalizer dispatch on `properties.additionalData.assessedResourceType` + `properties.category`:
  - **Code**: `type=vulnerability` or `code_quality`. Location from `properties.additionalData.sourceLocation` (filePath, startLine, endLine, snippet).
  - **Dependency**: `type=dependency`. `metadata.package = { name, version, ecosystem }`; `metadata.cve = properties.additionalData.cve`.
  - **Secret**: `type=secret`. `metadata.detector`, `metadata.validationFingerprints`.
- Severity from `properties.status.severity` (Low/Medium/High/Critical → lowercase).
- State: `properties.status.code` (`Healthy → resolved`, `Unhealthy → open`, `NotApplicable → dismissed`).
- Capabilities: `hasContainers=true (Azure resources as containers), canUpdateState=false, canAddComments=false, supportedEventTypes=[vulnerability, code_quality, dependency, secret, iac, posture]`.
- Some `additionalData.data` fields contain quoted-JSON values for date types (e.g. `Creation_Date`); repair via JSON walk + retype (no regex). Use `Crell/Serde` or a hand-rolled visitor over the decoded array.
**Definition of Done**:
- Pest tests for each of the three variants against committed ARG response fixtures.
- Pest test for the date-field repair.

### S3 — Defender: Posture + IaC
**Goal**: Cover the Defender-unique categories.
**Solution**:
- Separate KQL query for `microsoft.security/assessments` (full assessments, not sub-assessments) filtered to posture categories.
- IaC: sub-assessments with `additionalData.assessedResourceType == "IacTemplate"`.
- Normalizer: `type = posture` or `iac` (enums already added in M2-S1).
- Location for IaC from `additionalData.filePath`+`line`.
**Definition of Done**:
- Pest tests for both variants with committed fixtures.

### S4 — Defender: read-only capability enforcement
**Goal**: Make read-only nature explicit in UI.
**Solution**:
- `Source::pushEventState` throws `UnsupportedCapabilityException` for Defender.
- Triage UI (M3-S2) hides the state-edit action when the alert's source has `canUpdateState=false`; replaces with an informational tooltip "This source is read-only".
- Sync UI excludes Defender alerts from the pending-sync queue.
**Definition of Done**:
- Pest tests for UI-level enforcement.
- Pest test asserts `pushEventState` throws and is never called for Defender events.

---

## Epic E2 — Triage Artisan Commands

> **Security baseline (applies to all three commands):**
> - Subprocess execution exclusively via `Symfony\Component\Process\Process` with hardcoded argv arrays.
> - Binary allow-list enforced by `App\Triage\BinaryResolver`: returns one of `/usr/bin/git`, `/usr/bin/trivy`, `/usr/bin/java`, or rejects.
> - No shell interpolation anywhere. No use of `Process::fromShellCommandline`.
> - Per-command hard timeout (default 5 min) via `Process::setTimeout`.
> - Output size cap (default 100 MB) via streaming reader that aborts on overflow.
> - Working directory: ephemeral `storage/app/triage/{uuid}/` cleaned up in a `try/finally` regardless of outcome.
> - User-supplied URLs validated against an allow-list pattern (`/^(https:\/\/[a-z0-9.\-]+\/[\w\/.\-_]+(\.git)?)$/`) — but the URL is never spliced into a shell string; only passed as an argv element. Validation rejects URLs containing whitespace, quotes, `--`, `$`, backtick, or null bytes.

### S5 — `triage:codesearch`
**Goal**: `php artisan triage:codesearch {pat} {search} [--scope=<project|repo>] [--attach-to=<eventId>]`.
**Context**: Lookup AzDO code-search across an organization or scoped to project/repo, optionally attaching the JSON result to an alert.
**Solution**:
- Calls AzDO code-search REST: `POST https://almsearch.dev.azure.com/{org}/_apis/search/codeSearchResults?api-version=7.1` with body `{ searchText, $top: 100, $skip: 0, filters: { Project, Repository } }`.
- Auth: Basic with the provided PAT.
- Result formatted via Spectre-equivalent (`league/climate` or Symfony Console table) for human reading.
- When `--attach-to` provided:
  - Insert row into `event_attachments` (created in S8): `kind=codesearch-json, mime=application/json, name=codesearch-{timestamp}.json, payload=<json>`.
  - Audit `recordTriageRun(command=codesearch, event_id)`.
**Definition of Done**:
- Pest tests covering: missing args validation; happy path with mock HTTP; attachment persistence.
- Pest test asserts PAT never logged.

### S6 — `triage:trivy`
**Goal**: `php artisan triage:trivy {git_url} [--attach-to=<eventId>]`.
**Context**: Run trivy filesystem scan against a freshly-cloned repo; attach the SARIF output to an alert.
**Solution**:
- Validate `git_url` against the allow-list regex.
- Create temp dir `storage/app/triage/{uuid}/clone`.
- Clone via `Process` with argv `['/usr/bin/git', 'clone', '--depth', '1', '--no-tags', '--', $gitUrl, $clonePath]`.
- Run trivy via `Process` with argv `['/usr/bin/trivy', 'fs', '--quiet', '--format', 'sarif', '--output', $sarifPath, '--skip-db-update', $clonePath]`.
  - Use `--skip-db-update` because Trivy DB is refreshed by a separate daily scheduled job (`UpdateTrivyDbJob` registered as part of this story).
- Read SARIF file, attach to event:
  - `event_attachments` row: `kind=trivy-sarif, mime=application/sarif+json, payload=<sarif bytes>`.
  - Audit `recordTriageRun(command=trivy, event_id)`.
- `try/finally` removes the temp dir.
- Hard limits: 5-minute total timeout (300s); 100 MB SARIF cap (truncation rejected → fail fast).
- Authorization: `triage.run-trivy` (Plan+).
**Definition of Done**:
- Pest test with a local fixture git repo (committed under `tests/Fixtures/repos/`); asserts SARIF attached, exit code 0.
- Pest test for timeout enforcement (using a slow fixture).
- Pest test asserts attempt to inject shell metacharacters (e.g. `; rm -rf /`) into the URL is rejected at validation.
- Pest test asserts temp dir cleaned on both success and failure paths.

### S7 — `triage:bfg`
**Goal**: `php artisan triage:bfg {git_url} {secret_list_file} [--attach-to=<eventId>]`.
**Context**: BFG Repo-Cleaner rewrites history removing secrets; output is a rewritten bundle for the operator to review and force-push manually (we do **not** auto-push).
**Solution**:
- Validate `git_url` (same as S6) and `secret_list_file` exists and is readable (size ≤ 1 MB).
- Temp dir `storage/app/triage/{uuid}/`.
- Bare clone: `['/usr/bin/git', 'clone', '--mirror', '--', $gitUrl, $repoPath]`.
- Run BFG: `['/usr/bin/java', '-jar', '/opt/bfg/bfg.jar', '--replace-text', $secretListFile, $repoPath]`. Capture stdout/stderr as the "BFG report".
- Run cleanup: `['/usr/bin/git', '-C', $repoPath, 'reflog', 'expire', '--expire=now', '--all']` then `['/usr/bin/git', '-C', $repoPath, 'gc', '--prune=now', '--aggressive']`.
- Create rewritten bundle: `['/usr/bin/git', '-C', $repoPath, 'bundle', 'create', $bundlePath, '--all']`.
- Attach to event (when `--attach-to` provided):
  - `event_attachments` row `kind=bfg-report, mime=text/plain, payload=<report>`.
  - `event_attachments` row `kind=bfg-bundle, mime=application/octet-stream, payload=<bundle bytes>` (size-capped at 50 MB).
- Audit `recordTriageRun(command=bfg, event_id)`.
- Display final instructions to the operator: "Bundle saved at attachment X. Review via `git clone <bundle>` and force-push manually if accepted."
- Authorization: `triage.run-bfg` (Plan+).
**Definition of Done**:
- Pest test using a tempo fixture repo with seeded secrets in a test branch; asserts BFG report mentions the rewritten file.
- Pest test asserts no auto-push attempt occurs.
- Pest test asserts secret_list_file size cap enforced.

---

## Epic E3 — Attachments

### S8 — Filament: triage attachments tab
**Goal**: Alert detail page exposes attached SARIF / BFG / codesearch artifacts.
**Solution**:
- Migration `event_attachments` (id, event_id FK, kind varchar, mime varchar, name varchar, payload longblob, size_bytes int, created_at, created_by_user_id nullable, created_by_command varchar nullable). Index `(event_id, kind)`.
- Eloquent model `EventAttachment` with binary `payload` cast (custom `BinaryCast`).
- Filament relation manager on the alert detail page listing attachments:
  - Columns: name, kind badge, mime, size (human-formatted), created_at.
  - Actions: Download (sends file with correct Content-Type/Content-Disposition), Delete (Plan+ only, audit row).
- Inline SARIF viewer for `kind=trivy-sarif`: parses SARIF JSON server-side and renders a Filament table of results (rule id, severity, file:line) with snippet syntax-highlighted via `tempest/highlight`.
**Definition of Done**:
- Pest tests for relation manager and download action.
- Pest test for SARIF viewer rendering.

### S9 — Filament: trigger triage commands from UI
**Goal**: Plan/Sync roles can launch triage commands on an alert without using the CLI.
**Solution**:
- Filament action group on alert detail page "Run triage" with three actions:
  - "Run Trivy" → prompts for `git_url` (defaulted from `event.metadata.repository_url` if present) → dispatches `App\Triage\RunTrivyJob`.
  - "Run BFG" → prompts for `git_url` + uploads `secret_list.txt` (stored in temp storage) → dispatches `App\Triage\RunBfgJob`.
  - "Run Code Search" → prompts for `query` + `scope` → dispatches `App\Triage\RunCodesearchJob` using the current user's AzDO PAT from `Vault`.
- Each job invokes the corresponding Artisan command's underlying service (refactored out of the command class in S5/S6/S7 — so command and job share logic).
- UI polls every 3 s for completion via a Filament livewire poll; on success the attachments tab refreshes and a toast notification fires.
**Definition of Done**:
- Pest integration tests for each of the 3 jobs (with binary commands stubbed via a `BinaryResolver` test double).
- UI smoke covering all 3 actions.

---

## Definition of Done — Milestone M5

- Defender for Cloud feeds Code/Dependency/Secret/IaC/Posture alerts into the local DB on schedule.
- All three triage Artisan commands work from CLI and from Filament UI.
- Attachments persisted and downloadable from the alert detail page.
- Subprocess security baseline verified: argv allow-list, no shell, timeouts, size caps, temp-dir cleanup.
- `vendor/bin/pint --test` clean; `vendor/bin/pest` green.
- Manual smoke runs Trivy and BFG against a public test repo successfully inside the container.
