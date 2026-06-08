# User Story

## Title
Render software system links as unified virtual systems and seed their membership suggestions after sync

## Context
Milestone 8 already models software-system links as `App\Models\SoftwareSystemLink` records with member systems. `SoftwareSystemLinkResource` and `ViewSoftwareSystemLink` expose the link record in Filament, but the current UI only shows the member list and a deep link to alerts. The closest existing pattern is `SecurityContainerLinkResource`, which already treats a virtual container as a browsable merged entity with members, alerts, and related context.

The legacy app treated linked systems as a unified browse surface rather than a dead-end relation record. Its `queryUnifiedSystems()` and `getUnifiedSystem()` behavior returned the merged virtual system, and the detail view loaded member systems together with their aggregated containers. That is the behavior the Laravel rewrite still lacks for software-system links.

The inference pipeline already knows how to generate `virtual_system_membership_candidate` suggestions through `App\Context\Inference\FuzzyMappingSuggestionGenerator`, and `FetchSourceJob` already emits `SyncRunFinished` after each sync attempt. What is missing is the automatic post-sync trigger for that generator, so new virtual-system membership suggestions are only produced when the generator is called manually.

## Problem
Software-system links are still treated as member-only admin records instead of browsable unified systems. Operators cannot review a link as a merged virtual entity with its member systems, aggregated containers, and aggregated alerts, so the Laravel UI does not reproduce the legacy unified-system workflow.

In addition, the source-sync pipeline does not automatically run the existing fuzzy suggestion generator, so virtual-system membership suggestions are not created as part of a normal sync run. That leaves the software-system-link workflow dependent on manual generator invocation instead of the normal sync lifecycle.

## Solution
Make software-system links behave like unified virtual systems in Filament and run the existing fuzzy inference generator automatically after every successful source sync. The software-system-link view must show the merged member-system surface, including aggregated containers and alerts, and the sync pipeline must invoke `FuzzyMappingSuggestionGenerator::generate()` through the `SyncRunFinished` event so virtual-system membership suggestions are produced without manual invocation.

## Implementation Instructions
1. Update `app-laravel/app/Models/SoftwareSystemLink.php`.
2. Add explicit aggregation helpers on `SoftwareSystemLink` for the merged virtual-system view:
   - resolve the member system IDs from the `members` relation;
   - build an aggregated containers query across all member systems;
   - build an aggregated events query across all member systems;
   - expose an `openAlertsCount()` helper that counts open alerts across those member systems.
3. Update `app-laravel/app/Filament/Resources/SoftwareSystemLinkResource.php` and `app-laravel/app/Filament/Resources/SoftwareSystemLinkResource/Pages/ViewSoftwareSystemLink.php`.
4. Keep the existing editable member list, but add read-only unified-system presentation for the merged virtual entity:
   - show summary counts for members, aggregated containers, and open alerts;
   - show a merged containers section sourced from all member systems;
   - show a merged alerts section sourced from all member systems;
   - keep the existing `View alerts` action, but have it continue to target the virtual system scope for the link.
5. Add `app-laravel/app/Listeners/GenerateInferenceSuggestions.php`.
6. Make the listener handle `App\Events\SyncRunFinished`, return immediately unless `$event->run->status === 'success'`, and call `App\Context\Inference\FuzzyMappingSuggestionGenerator::generate()` for successful runs only.
7. Register the listener in `app-laravel/app/Providers/AppServiceProvider.php` alongside `BustDashboardCache`.
8. Keep `app-laravel/app/Sync/FetchSourceJob.php` responsible only for sync persistence and `SyncRunFinished` emission; do not move inference generation into the job.
9. Update `app-laravel/tests/Feature/Sync/FetchSourceJobTest.php` so a successful sync with deterministic AzDO-style metadata creates the expected pending `virtual_system_membership_candidate` suggestions and a failed sync does not.
10. Add or update a Filament feature test, preferably `app-laravel/tests/Feature/Filament/SoftwareSystemLinkResourceTest.php`, to assert that the software-system-link view shows the merged containers and alerts surface for a virtual system link and still exposes the existing header action behavior.
11. Run Pint and the relevant Pest suites after the change, and fix any failures before finishing.

## Definition of Done
- Software-system links are browsable as unified virtual systems instead of member-only records.
- The link view shows the merged member-system surface, including aggregated containers and alerts.
- A successful source sync automatically runs the existing fuzzy suggestion generator through the normal `SyncRunFinished` event path.
- Failed source sync runs do not generate new inference suggestions.
- Relevant automated tests are added or updated for the sync wiring and the user-visible virtual-system view.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless this story explicitly requires them.