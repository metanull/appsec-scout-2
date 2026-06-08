# User Story

## Title
Automatically reconcile work items after tracker project link changes

## Context
Milestone 8 already has the reconciliation worker path in place. `App\Trackers\Reconciliation\ReconciliationService` finds matching tracker work items by URL, creates `WorkItemLink` rows, and records audit events. `App\Trackers\ReconcileAllJob` wraps the full reconciliation pass and stores the last-run summary in cache. The manual entry points already exist in the UI: the Operations page can dispatch the global reconciliation job, and the per-event security-event view can dispatch the same underlying reconciliation flow for one alert.

Tracker-project links are the data the reconciliation worker needs in order to match alerts to existing tracker work items. Those links are written in three places: accepted tracker-project inference suggestions, the tracker-project-links Filament relation manager, and the tracker-project learning that happens when work items are created or linked. Those writes already persist the durable mapping data, but they do not currently trigger reconciliation automatically.

## Problem
Work item reconciliation only runs when an operator manually starts it. When tracker-project links are created or revised through accepted inference suggestions, relation-manager edits, or work-item learning, the system does not immediately launch the reconcile pass that scans alert URLs and creates missing `WorkItemLink` rows. As a result, newly learned tracker mappings do not produce linked work items until someone remembers to run reconciliation by hand.

## Solution
Queue `App\Trackers\ReconcileAllJob` automatically after every committed tracker-project-link create or update so the existing reconciliation worker runs as soon as new mapping data is durable. Keep the job unique, keep the existing manual reconciliation actions, and do not change the reconciliation matching rules or cache summary format.

## Implementation Instructions
1. Update `app-laravel/app/Models/TrackerProjectLink.php`.
2. In `TrackerProjectLink::booted()`, keep the existing audit logging in the `created` and `updated` callbacks.
3. After a tracker-project link is created or updated successfully, queue `App\Trackers\ReconcileAllJob` with after-commit semantics so the job only runs after the database transaction that created the mapping has committed.
4. Do not queue reconciliation from the `deleted` callback.
5. Do not change `App\Trackers\Reconciliation\ReconciliationService`, `App\Trackers\ReconcileAllJob`, `App\Filament\Pages\OperationsPage`, or `App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent`; their current manual reconciliation behavior must remain unchanged.
6. Add or update `app-laravel/tests/Feature/Context/Inference/InferenceSuggestionApplierTest.php` so accepting a tracker-project-link inference suggestion asserts that `ReconcileAllJob` is dispatched after the link is created.
7. Add or update `app-laravel/tests/Feature/Trackers/TrackerProjectLinkerTest.php` so creating or linking work items asserts that `ReconcileAllJob` is dispatched when `TrackerProjectLinker::learnFromEvents()` persists tracker-project links.
8. Add or update `app-laravel/tests/Feature/Trackers/TrackerProjectLinkTest.php` so direct create and update of a `TrackerProjectLink` dispatch reconciliation, and delete does not.
9. Run the project quality checks after the change, including Pint and the relevant Pest suites, and fix any failures before finishing.

## Definition of Done
- Every committed create or update of a tracker-project link automatically queues one reconciliation run after the transaction commits.
- The existing manual reconciliation actions on the Operations page and the security-event view still work unchanged.
- Reconciliation still uses the current matching rules and still writes the same `WorkItemLink` and audit records.
- Relevant automated tests are added or updated for the model hook and the user-visible mapping-write paths.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless this story explicitly requires them.