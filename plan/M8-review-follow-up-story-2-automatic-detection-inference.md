# User Story

## Title
Generate inference suggestions automatically after source sync

## Context
Milestone 8 already has the complete inference review model: `InferenceSuggestion` stores pending candidate mappings, `InferenceSuggestionResource` is the operator review surface, and `FuzzyMappingSuggestionGenerator` can derive deterministic suggestions from normalized metadata. The source sync pipeline also already persists the facts the generator needs. `FetchSourceJob` upserts systems, containers, and events, then dispatches `SyncRunFinished` after each run.

The legacy app handled this as a post-sync inference pass: the source sync produced rich metadata, then a separate inference step created reviewable mapping candidates for the operator queue. In the Laravel app, that second step exists only when `FuzzyMappingSuggestionGenerator::generate()` is called directly in tests.

## Problem
Successful source sync runs do not currently trigger inference generation. As a result, the inference review page stays empty unless someone manually invokes the generator, even when the freshly synced metadata already contains the evidence needed to create virtual-system membership, virtual-container membership, repository mapping, and tracker-project mapping suggestions.

## Solution
Wire a post-sync inference pass into the existing `SyncRunFinished` event so every successful source sync automatically runs `FuzzyMappingSuggestionGenerator::generate()` exactly once for that run. The inference pass must be skipped for failed sync runs. The review queue must then contain the pending suggestions produced from the newly synced metadata without requiring manual intervention.

## Implementation Instructions
1. Create a new listener at `app-laravel/app/Listeners/GenerateInferenceSuggestions.php`.
2. Make the listener handle `App\Events\SyncRunFinished` and inject `App\Context\Inference\FuzzyMappingSuggestionGenerator` into the listener method or constructor.
3. In the listener, return immediately unless `$event->run->status === 'success'`.
4. For successful runs, call `generate()` on the inference suggestion generator after the sync run has completed. Do not duplicate the generator logic inside `FetchSourceJob`.
5. Register the new listener in `app-laravel/app/Providers/AppServiceProvider.php` alongside the existing `BustDashboardCache` listener so the post-sync inference pass is part of the normal source-sync lifecycle.
6. Keep `FetchSourceJob`'s current persistence flow intact: it must still upsert systems, containers, and events, then emit `SyncRunFinished` on both success and failure.
7. Add or update `app-laravel/tests/Feature/Sync/FetchSourceJobTest.php` with a fake source that emits deterministic context metadata and assert that a successful sync creates the expected pending `InferenceSuggestion` rows through the new event listener, while a failed sync does not create new suggestions.
8. Add or update `app-laravel/tests/Feature/Filament/InferenceSuggestionReviewResourceTest.php` so the review page is exercised with suggestions produced by an actual sync run, proving the operator queue is populated by the automatic inference step and not by manual seeding.
9. Keep the existing direct generator coverage in `app-laravel/tests/Feature/Context/Inference/FuzzyMappingSuggestionGeneratorTest.php` so the deterministic matching rules and idempotency behavior remain covered.
10. Run the project quality checks after the change: `. ./scripts/invoke-check.ps1 -Check lint-fix` then `. ./scripts/invoke-check.ps1 -Check all`, and fix any failures before finishing.

## Definition of Done
- A successful source sync automatically generates pending inference suggestions from normalized metadata facts.
- Failed source sync runs do not generate inference suggestions.
- The inference review page receives real suggestion records from the sync pipeline without manual generator invocation.
- Relevant automated tests are added or updated for the sync wiring and user-visible review queue behavior.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in check results.
- No unrelated behavior, migrations, dependencies, or documentation are changed.