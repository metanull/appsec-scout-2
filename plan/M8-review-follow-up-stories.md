# Milestone 8 Review Follow-up Stories

Independent review found four valid Milestone 8 follow-up issues. Each story below describes one implementation change and is ready to convert into a GitHub issue.

## Story 1

### Title

Replace raw SQL predicates in Milestone 8 Filament queries

### Context

Milestone 8 added Filament resources and table/infolist query customization for inference suggestions, security events, security containers, and software systems. The project rules for `app-laravel/` require Laravel, Eloquent, query builders, casts, relationships, and scopes before raw SQL. Database portability rules also require application queries to remain portable between MySQL and SQLite when framework-level alternatives exist.

Current code uses raw SQL in these Milestone 8 Filament surfaces:

- `app-laravel/app/Filament/Resources/InferenceSuggestionResource.php` uses `orderByRaw` for pending-first sorting.
- `app-laravel/app/Filament/Resources/SecurityEventResource.php` uses `orderByRaw` for severity sorting.
- `app-laravel/app/Filament/Resources/SecurityContainerResource.php` uses `whereRaw` for open event counts.
- `app-laravel/app/Filament/Resources/SoftwareSystemResource.php` uses `whereRaw` for open, critical, high, and medium event counts.

### Problem

The raw SQL clauses duplicate behavior that should be expressed through framework-native query APIs or small model scopes. Simple equality predicates such as `state = 'open'` and `severity = 'critical'` have direct `where()` equivalents. Custom enum ordering is embedded as SQL strings inside Filament resources, which makes the resources harder to maintain, weakens portability discipline, and conflicts with the project's framework-first query guidance.

### Solution

Move the Milestone 8 Filament query predicates to framework-native Eloquent/query-builder expressions and centralize repeated event-count predicates in model scopes or local helper methods. Keep the existing user-visible sorting and counts unchanged.

### Implementation Instructions

1. In `app-laravel/app/Models/SecurityEvent.php`, add reusable query scopes for the repeated event predicates used by Filament context views:
   - `scopeOpen(Builder $query): Builder` filters `state` with `EventState::Open->value` using `where()`.
   - `scopeWithSeverity(Builder $query, EventSeverity $severity): Builder` filters `severity` with `$severity->value` using `where()`.
2. Update `app-laravel/app/Filament/Resources/SecurityContainerResource.php` so infolist counts and `withCount()` aggregate callbacks call the new scopes instead of `whereRaw()`.
3. Update `app-laravel/app/Filament/Resources/SoftwareSystemResource.php` so infolist counts and `withCount()` aggregate callbacks call the new scopes instead of `whereRaw()`.
4. Replace the raw pending-first and severity-priority ordering in `InferenceSuggestionResource` and `SecurityEventResource` with framework-owned code:
   - Add explicit integer sort-rank accessors or query scopes on the relevant model only if a persistent or computed model-level sort concept is needed by more than the table.
   - Otherwise, use Filament table grouping/default sorting and query-builder `orderBy()` calls that preserve the current visible order without embedding raw SQL strings in the resource.
5. Search the touched Filament resources for sibling `whereRaw()` or `orderByRaw()` uses introduced by Milestone 8 and replace any equality or LIKE predicates that have direct `where()` or `whereLike()` equivalents.
6. Add or update Pest feature tests covering the affected table query behavior:
   - Security container open alert count uses only open events.
   - Software system severity counts use only matching severities.
   - Inference suggestions still show pending suggestions before non-pending suggestions.
   - Security events still sort critical, high, medium, low, informational before older lower-priority records.
7. Run Pint, PHPStan, and the relevant Pest tests through Docker from the repository root.

### Definition of Done

- Milestone 8 Filament query code no longer uses raw SQL for predicates with framework-native alternatives.
- User-visible counts and table ordering are unchanged.
- Query code follows Eloquent/query-builder, enum, and database-portability conventions.
- Relevant automated tests are added or updated for the business logic and user-visible behavior.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless explicitly required by this story.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.

## Story 2

### Title

Replace hard-coded Filament role checks with permission gates

### Context

Milestone 8 added authorization paths for repository providers, inference review, virtual security-container links, curated links, and context-quality navigation. The project uses Spatie Permission with cumulative roles: Reader, Triage, Plan, Sync, and Admin. Project rules require protected actions to be gated through Laravel policies, Spatie permissions, Filament authorization hooks, or Laravel authorization APIs. Filament UI access must not embed role names where permission checks or policies express the capability.

Current Milestone 8 Filament code embeds `Plan` and `Admin` role names in these authorization paths:

- `app-laravel/app/Filament/Resources/RepositoryProviderResource.php`
- `app-laravel/app/Filament/Resources/InferenceSuggestionResource.php`
- `app-laravel/app/Filament/Resources/SecurityContainerLinkResource.php`
- `app-laravel/app/Filament/Resources/Shared/RelationManagers/CuratedLinksRelationManager.php`
- `app-laravel/app/Filament/Support/ContextQualityIndicatorSupport.php`

### Problem

Hard-coded role checks make UI access depend on role names rather than capabilities. That conflicts with the repository's authorization rules, makes future role changes riskier, and creates inconsistent Filament behavior compared with adjacent pages and widgets that already use `$user->can(...)` permission gates.

### Solution

Replace Milestone 8 role-name checks in Filament authorization paths with permission-based gates or policy methods. Preserve the current cumulative role behavior by assigning any new permission to the appropriate roles in `RolePermissionSeeder` and validating it with tests.

### Implementation Instructions

1. Define the exact capabilities required by the affected Milestone 8 UI paths:
   - Repository provider management requires an integration or planning capability and must not rely on `Plan` or `Admin` role names.
   - Inference review requires a planning capability.
   - Virtual security-container link mutation requires a planning capability.
   - Curated link mutation requires a planning capability.
   - The context-quality link to inference review must be visible only to users with the inference-review capability.
2. Reuse an existing permission when it already matches the capability. Prefer existing permissions such as `work-items.link`, `work-items.create`, or `admin.integrations` only when their business meaning exactly matches the action.
3. If no existing permission exactly matches inference review or context curation, add a narrowly named permission to `RolePermissionSeeder` and assign it through the cumulative role model so Plan and Admin users retain current access while access remains permission-based.
4. Update `RepositoryProviderResource::canViewAny()`, `canCreate()`, `canEdit()`, and `canDelete()` to use permission checks or policy methods only.
5. Update `InferenceSuggestionResource::canReview()` to use the selected permission or policy method only.
6. Update `SecurityContainerLinkResource::canMutate()` to use the selected permission or policy method only.
7. Update `CuratedLinksRelationManager::canMutate()` to use the selected permission or policy method only.
8. Update `ContextQualityIndicatorSupport::qualityUrl()` to call the same permission check that controls access to inference review.
9. Add or update Pest tests for Filament authorization behavior:
   - A user with the required permission can see and use each affected UI capability.
   - A user without the required permission cannot see or use each affected UI capability.
   - Plan and Admin roles retain the intended access through assigned permissions.
   - No test asserts access by checking role names directly for these paths.
10. Run Pint, PHPStan, and the relevant Pest tests through Docker from the repository root.

### Definition of Done

- The cited Filament authorization paths no longer call `hasRole()` or `hasAnyRole()`.
- Access is enforced through permissions, policies, or Laravel authorization APIs at each UI boundary.
- The cumulative role model is preserved through permission assignment, not embedded role checks.
- Relevant automated tests are added or updated for the business logic and user-visible behavior.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless explicitly required by this story.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.

## Story 3

### Title

Require confirmation before deleting curated links

### Context

Milestone 8 added curated links as editable related context on security entities. Curated link deletion is a destructive Filament table action in `CuratedLinksRelationManager`. Adjacent Milestone 8 relation-manager deletion behavior, such as repository mapping deletion, already uses Filament `requiresConfirmation()`.

### Problem

The curated link delete action runs immediately after clicking Delete. This creates an accidental-delete risk and makes destructive action behavior inconsistent with adjacent Milestone 8 UI code and project UX defaults.

### Solution

Add a Filament confirmation modal to the curated link delete action while preserving the existing service call, authorization visibility, audit behavior, and success notification.

### Implementation Instructions

1. Update `app-laravel/app/Filament/Resources/Shared/RelationManagers/CuratedLinksRelationManager.php`.
2. On `Action::make('delete')`, add `->requiresConfirmation()` before the action callback.
3. Keep the existing `label`, `icon`, `color`, `visible`, `CuratedLinkService::delete()`, and success notification behavior unchanged.
4. Add or update a Pest feature test that mounts a supported owner relation manager and verifies the curated link is not deleted unless the confirmation flow is completed.
5. Run quality check after implementing, using exclusively scripts/invoke-check.ps1

### Definition of Done

- Curated link deletion displays a Filament confirmation modal before deleting.
- Confirmed deletion still deletes through `CuratedLinkService` and sends the existing success notification.
- Unauthorized users still cannot see or run the delete action.
- Relevant automated tests are added or updated for the business logic and user-visible behavior.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless explicitly required by this story.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.

## Story 4

### Title

Move Operations page presentation to Filament primitives

### Context

Milestone 8 added an operator-facing Operations page under Filament. The page already uses Filament header actions, widgets, notifications, and a table in PHP, but its Blade view manually arranges the page with custom `div` classes, manually renders widgets with `@livewire`, and presents the reconciliation summary through custom markup. The active Filament rules require operator UI to use Filament resources, pages, actions, tables, schemas, widgets, and form fields before custom Blade markup or classes.

### Problem

The Operations page depends on custom Blade presentation for layout and summary display even though Filament page/widget primitives cover the visible content. This creates styling and behavior inconsistency with the rest of the panel and violates the strict Filament-first expectation for Milestone 8 UI surfaces.

### Solution

Render the Operations page using Filament page, widget, stats, and table primitives. Keep the operational actions, authorization, queue behavior, failed-job table behavior, and notifications unchanged.

### Implementation Instructions

1. Create a Filament widget for the reconciliation summary, preferably a `StatsOverviewWidget` with one `Stat` showing the last successful reconciliation run and new-link count.
2. Move the reconciliation summary logic out of the custom Blade view and into the widget or a small service/helper used by the widget.
3. Register the reconciliation summary widget and existing operations widgets through Filament page widget hooks such as `getHeaderWidgets()` or `getFooterWidgets()` instead of manually looping through `$this->getWidgets()` in Blade.
4. Keep `OperationsPage` actions implemented as Filament `Action` and `ActionGroup` instances.
5. Keep the failed-jobs listing implemented as a Filament `Table` on the page or move it to a `TableWidget` if that removes the need for a custom page view.
6. Remove custom layout classes and manual `@livewire` widget rendering from `app-laravel/resources/views/filament/pages/operations-page.blade.php`.
7. If a minimal Blade view remains necessary for Filament page/table rendering, it must contain only the Filament page component and the Filament table output, with no custom presentation classes or manual widget rendering.
8. Add or update Pest/Livewire tests verifying that authorized users can access the Operations page, see the reconciliation summary through the Filament widget, and see the failed-jobs table.
9. Run lint fix after implementeing, using exclusively `scripts/invoke-lint-fix.ps1`
10. Run quality check after implementing, using exclusively `scripts/invoke-check.ps1` fix unil all green

### Definition of Done

- Operations page presentation uses Filament page, widget, stats, and table primitives.
- The custom Operations page Blade view no longer contains custom layout/styling classes or manual `@livewire` widget loops.
- Existing operations actions, permissions, notifications, queue dispatch behavior, and failed-job table behavior are unchanged.
- Relevant automated tests are added or updated for the business logic and user-visible behavior.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless explicitly required by this story.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.