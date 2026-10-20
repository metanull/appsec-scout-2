# AppSec Scout — Concept: Upstream Source Capabilities

`App\Sources\ValueObjects\SourceCapabilities` declares, per Source, whether it can update an
alert's state, update its severity, add a comment alongside a push, or push a standalone comment.
This document gives the full per-operation picture — verified against each Source's real upstream
API, not just the flags — and explains what appsec-scout does when a staged local change turns out
to have nowhere to go. See [docs/concepts/triage.md](triage.md#staging-vs-pushing-a-change) for how
staging and pushing work day-to-day, and
[docs/concepts/sources-trackers-source-control.md](sources-trackers-source-control.md#capability-matrices)
for the `SourceCapabilities`/`TrackerCapabilities` shape.

## Per-source capability matrix

| Source | State push | Severity push | Comment attached to a state/severity push | Standalone comment push |
| --- | --- | --- | --- | --- |
| **AzDO Advanced Security** | Yes — `PATCH` on the alert, `state` field | **No** — severity is server-computed and read-only; AzDO's own API explicitly rejects attempts to set it | Only as `dismissedComment`, and only when transitioning to `Dismissed` — no comment field on any other state transition | **No** — no thread/discussion API exists for alerts at all |
| **AppScan on Cloud (ASoC)** | Yes — `PUT api/v4/Issues/Application/{appId}?odataFilter=Id eq {issueId}`, `Status` field (body holds only the new values; a 200 with `NProvidedIssues: 0` means no issue matched) | **No** — the v4 `UpdateIssue` body has only `ExternalId`, `Status`, `Comment` and `CustomFields` | Yes — `Comment` rides the same `Status` update payload | **No** — the `Comments` endpoint is read-only (`GET` only); there is no create-comment operation |
| **Detectify** | Yes — `POST vulnerabilities/uuid/{uuid}/{statusAction}/` | No — no severity-set endpoint exists anywhere in the API | **No** — the status-change endpoints take no comment/note field at all | **No** |

**AzDO dismissal reason.** An AzDO dismissal also carries `dismissedReason` (the `DismissalType` enum of the
alert update API, api-version 7.2-preview.1). The operator chooses it when staging the dismissal —
required for AzDO alerts, in the Filament state-change forms and in MCP `change-alert-state`
(`dismissal_reason`) — and it is kept in `security_events.pending_dismissal_reason` until the
change is pushed or discarded. Offered values: `acceptedRisk`, `falsePositive` (code, dependency,
secret and license alerts), `notDistributed` (dependency alerts only — the dependency never
ships to end users) and `agreedToGuidance` (license alerts only — the license guidance is followed).
The service-set values (`unknown`, `fixed`, `toolUpgrade`) are not offered. A dismissal staged before the reason could be chosen pushes the
reason in the alert's metadata, else `falsePositive`.

None of the three connected sources expose an API for posting a comment independent of a status
change — there is nowhere to send that request. `SourceCapabilities::canPushStandaloneComment`
names this capability so a future Source can declare it; there is no `Source` contract method that
acts on it yet (see below).

## What happens when a staged change has nowhere to go

Local-first means an operator can always stage a state, severity, or comment change locally,
regardless of whether the connected Source can ever receive it — appsec-scout never blocks or
warns against a local edit at the moment it's made. The question of "can this actually push
anywhere" is answered later, when a Sync operator runs a push (`App\Sync\PendingStatePusher`, via
`Sync -> Pending Sync`), by `App\Sync\PendingSyncResolver`:

- **Pushable** (a state change; a severity change when the Source declares `canUpdateSeverity:
  true`): attempted via `Source::pushEventState()` as normal — success clears the staged field(s)
  and `is_dirty`; failure retries (up to 3 attempts) with the error recorded.
- **Not pushable** (a severity change when the Source doesn't declare `canUpdateSeverity: true`; a
  standalone comment, always): resolved as **local-only** — a system-authored comment is left on
  the alert's own Comments tab explaining exactly why ("`<Source>` does not support updating alert
  severity" / "does not support receiving a comment independent of a state or severity change"), an
  `ErrorLog` warning is recorded, and `is_dirty` is cleared. The staged value itself (e.g.
  `pending_severity`) is **not** cleared — it stays visible as a durable local annotation,
  distinguishing "operator's own read on this alert" from "what the source last reported."
- **Misconfigured** (a Source declares `canPushStandaloneComment: true` with no actual push
  mechanism): the event is left dirty and an `ErrorLog` error is recorded, rather than silently
  claiming resolution for a capability that was declared but never wired up.

This resolution runs both from a live `PendingStatePusher` push and from the one-off
`events:recompute-pending-sync` Artisan command, which re-evaluates every currently-dirty event
against the same rules — safe to re-run: once an event resolves, it's no longer dirty and the
query that selects candidates skips it.
