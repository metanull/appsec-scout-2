# AppSec Scout — Concept: Automated Discovery

appsec-scout has four independent mechanisms that automatically create or set a relationship
between two records — a `SoftwareAsset`, a `TrackerProjectLink`, a `WorkItemLink`, or a
correlation between a Local Finding and an Alert — without an operator doing it record-by-record.
None of the four shares code with any other (no common base class, trait, or helper); each was
written independently to solve its own problem. This document names the pattern they all follow
and compares them side by side. The deep mechanics of each one stay documented where a reader
would naturally look for them — this page only synthesizes and cross-links.

## The Shared Philosophy

Every one of these four mechanisms follows the same conservative rules, even though none of them
was written by referencing the others:

- **Confidence-gated, never guesses.** Each one either finds a confident match/decision or does
  nothing — there is no fuzzy, scored, "probably" match anywhere. There is also no confidence
  *score* stored anywhere, and no suggestion queue an operator reviews and accepts/rejects.
- **Additive-only — never overwrites a manual decision.** All four check first whether a
  relationship already exists and back off if it does. Automation only ever fills a gap; it never
  second-guesses something a human (or an earlier automated pass) already decided.
- **The result is visible and reversible through the normal UI**, with one exception (see below)
  — auto-created rows look and behave exactly like manually-created ones, so an operator can
  always inspect, correct, or remove what automation produced.

## Comparison

| Mechanism | Trigger | Creates / sets | Matching logic | Confidence gate | Reversible? |
| --- | --- | --- | --- | --- | --- |
| `AzDoProjectLinker::linkSystemToAsset()` | Inline, every time an AzDO `SoftwareSystem` is upserted (normal fetch cycle or `assets:sync-azdo-projects`) | A new `SoftwareAsset`, 1:1 | None — always creates a fresh Asset, no name/similarity matching against existing ones | Only acts if the System has no Asset yet | Yes — detach/reattach via Filament |
| `TrackerProjectLinker::learnFromEvents()` | Inline, after every "create work item" / "link existing work item" action | A `TrackerProjectLink` at System **and** Container level | None — deterministic: the exact System/Container ids of the events just acted on, and the project just used | Idempotent upsert; never marks the new link `is_default`; never overwrites an existing `project_name` | Yes — edit/delete via relation manager |
| `ReconciliationService` | On-demand ("Find existing work items" button) or background sweep (`ReconcileAllJob`) | A `WorkItemLink` | URL cross-reference: the alert's own URL matched against text mined from candidate tracker issues | Only links when a URL match is found — no title/description similarity guessing | Yes — "Unlink" action |
| `SecurityEventCorrelator::correlate()` | Inline, every time a Local Finding is ingested (including re-scans) | `correlated_security_event_id` on a `LocalFinding` | Vulnerability: exact package name + version. Secret: exact file path + line within 2. Code quality: never correlated | Only sets when a match is found; re-running never *clears* a previous successful correlation | **No** — `LocalFindingResource` now supports status/severity/comment/tracker-link actions, but none of them touch `correlated_security_event_id`; there is still no action to clear or correct a wrong correlation |

## Each Mechanism, and Where to Read the Full Story

- **Asset auto-linking** (AzDO only) — [docs/concepts/asset-system-container-alert.md](asset-system-container-alert.md#software-asset)
- **Tracker project auto-learning** — [docs/concepts/links-and-defaults.md](links-and-defaults.md#how-links-get-created-manual-or-auto-learned)
- **Work item reconciliation** — [docs/concepts/triage.md](triage.md#reconciliation-the-same-linking-mechanism-two-triggers)
  and [docs/concepts/links-and-defaults.md](links-and-defaults.md#reconciliation-scoping)
  (how the UI button and the underlying service both fall back to searching every configured
  tracker project when the alert's System/Container has no scoped link)
- **Local Finding correlation** — [docs/concepts/asset-system-container-alert.md](asset-system-container-alert.md#correlation-linking-a-local-finding-back-to-an-alert)

## What appsec-scout Does Not Do

Since this list makes the pattern visible, it's worth being explicit about its boundary. There is
no cross-Source System-to-System matching (nothing guesses that an AzDO project and an ASoC
application with similar names are "the same thing" — Asset grouping across Sources besides
AzDO's own deterministic default is always a manual decision), no name/text-similarity scoring
anywhere in the codebase, and no accept/reject suggestion workflow. If a future mechanism needs
that kind of fuzzy, human-reviewed matching, it would be new territory for the app, not an
extension of any of the four patterns above.
