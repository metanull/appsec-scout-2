# User Story

## Title
Preserve full AzDO problem text in work item descriptions

## Context
Milestone 8 already has the work-item creation pipeline in place. `App\Trackers\WorkItemService` delegates description assembly to `App\Trackers\DescriptionBuilder`, and the builder already renders the alert link section, source-link section, event description section, remediation section, and tracker links. That means the tracker writer is not the missing piece.

The gap is earlier in the AzDO source normalization path. `App\Sources\AzDo\AzDoNormalizer::toEvent()` sets `EventDto::description` from `buildDescription()`, but the current implementation only keeps the rule description. The legacy AzDO normalizer also preserved the upstream full problem narrative from `tools[0].rules[0].fullDescription.text`, and that text is what operators need to see in the generated tracker work item when they create a work item from an AzDO finding.

## Problem
AzDO-generated work items lose the upstream problem narrative because the normalization step discards `fullDescription.text` before the event reaches `WorkItemService`. As a result, the created tracker item can contain the short rule summary and source links, but not the detailed finding text that explains the alert in the upstream system.

## Solution
Extend AzDO event normalization so the normalized event description preserves the upstream problem narrative from `tools[0].rules[0].fullDescription.text` in addition to the existing rule description. Leave `WorkItemService` and `DescriptionBuilder` as the consumer path; they must continue to render the event description that normalization supplies.

## Implementation Instructions
1. Update `app-laravel/app/Sources/AzDo/AzDoNormalizer.php`.
2. In `AzDoNormalizer::buildDescription()`, keep the current rule-description behavior and append `tools[0].rules[0].fullDescription.text` when it is present and non-empty.
3. Use the same deterministic blank-line separation that the legacy normalizer used for multi-part descriptions. Do not move this logic into `WorkItemService`, `DescriptionBuilder`, or any tracker implementation.
4. Keep `AzDoNormalizer::toEvent()` wiring unchanged except for the updated description content returned by `buildDescription()`.
5. Update `app-laravel/tests/Unit/Sources/AzDo/AzDoNormalizerTest.php` with a synthetic AzDO alert that includes both `rule.description` and `rule.fullDescription.text`, then assert that `toEvent()` preserves the full description text in `EventDto::description`.
6. Update `app-laravel/tests/Fakes/FakeTracker.php` so the fake tracker stores the most recent `CreateWorkItemRequest` it receives.
7. Update `app-laravel/tests/Feature/Trackers/WorkItemServiceTest.php` so single-event and grouped work-item creation assert the tracker request description still contains the existing alert-link output from `DescriptionBuilder` and now also contains the preserved AzDO full problem text.
8. Keep the existing `DescriptionBuilder` snapshot coverage intact so the tracker description format remains locked down for non-AzDO inputs.
9. Run Pint and the relevant Pest suites after the change, and fix any failures before finishing.

## Definition of Done
- AzDO normalization preserves the upstream full problem text in `EventDto::description` when `fullDescription.text` is available.
- Single-event and grouped work-item creation from AzDO findings include the preserved problem narrative in the generated tracker payload.
- `WorkItemService` and `DescriptionBuilder` continue to own tracker rendering without custom AzDO-specific fallback logic.
- Relevant automated tests are added or updated for the normalization boundary and the work-item creation path.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless this story explicitly requires them.