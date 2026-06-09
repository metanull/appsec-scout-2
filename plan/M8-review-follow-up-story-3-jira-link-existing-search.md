# User Story

## Title
Migrate Jira work-item search to the enhanced JQL search endpoint

## Context
The alert detail page exposes the `Link existing` action, which uses `App\Trackers\WorkItemFormOptions` to populate the work-item picker for the configured tracker. For Jira, that picker flows through `App\Trackers\Jira\JiraTracker::searchWorkItems()` and `App\Trackers\Jira\JiraClient::searchWorkItems()`.

The Jira tracker already works for project listing, assignee listing, issue creation, issue lookup, and reconciliation. The reconciliation path already uses Jira’s enhanced JQL search endpoint. The work-item picker path still uses the deprecated issue search endpoint and is the only Jira search path that fails with HTTP 410 in normal use.

The reported runtime failure is a Jira Cloud removal response:

- `GET /rest/api/3/search?...` returns `410 Gone`
- Jira instructs the client to migrate to `/rest/api/3/search/jql`

The current UI failure appears while typing into the `Link existing` search field on the alert view page, because the tracker runtime is calling the removed search endpoint before any choices can be returned.

## Problem
Jira work-item search for the `Link existing` action still calls the removed `/rest/api/3/search` endpoint. That endpoint now returns `410 Gone`, which causes the Filament page to fail while loading the work-item picker and prevents users from linking an existing Jira issue from the alert view.

This is a client-side endpoint bug, not a credential problem. Other Jira tracker functions already succeed, which confirms the configured host, email, and API token are valid enough to reach Jira and perform other tracker operations.

## Solution
Replace the Jira work-item search implementation with Jira’s enhanced JQL search endpoint, `GET /rest/api/3/search/jql`, while preserving the current search behavior, field selection, result mapping, and limit handling. The `Link existing` action must return issue suggestions without throwing a page-load error.

## Implementation Instructions
1. Update `app-laravel/app/Trackers/Jira/JiraClient.php`.
2. Change `JiraClient::searchWorkItems()` to call `GET /rest/api/3/search/jql` instead of `GET /rest/api/3/search`.
3. Keep the JQL semantics unchanged: search within the requested project, match the issue summary against the typed query, and order by created date descending.
4. Keep the requested field set unchanged so `mapWorkItem()` can still hydrate `WorkItemDto` from the response. Request the fields needed for the picker and issue mapping: `summary`, `status`, `labels`, `priority`, `assignee`, `parent`, and `description`.
5. Preserve the current result limit behavior and continue returning mapped `WorkItemDto` objects from the Jira response.
6. Do not change `JiraTracker::searchWorkItems()`, `WorkItemFormOptions`, or the Filament action wiring unless a compile-time adjustment is required by the client signature.
7. Update `app-laravel/tests/Feature/Trackers/JiraTrackerTest.php` to assert that the search request hits `/rest/api/3/search/jql` and that the returned response is mapped into work items correctly.
8. Update `app-laravel/tests/Feature/Trackers/WorkItemFormOptionsTest.php` to exercise the `linkSchema()` path for Jira work-item lookup so the `Link existing` picker remains usable from the alert detail workflow.
9. Use the Jira credentials and settings already stored in the app to perform a live smoke verification of the work-item search path against a configured Jira project, and confirm the request returns suggestions instead of `410 Gone`.
10. Run the relevant Pint and Pest checks after the change.

## Definition of Done
- The Jira `Link existing` work-item picker no longer calls the removed `/rest/api/3/search` endpoint.
- The picker uses `/rest/api/3/search/jql` and returns suggestions successfully.
- The alert view page no longer fails with `### Error while loading page` when a user types into the Jira existing-work-item search field.
- The Jira tracker still lists projects, assignees, and reconciliation candidates correctly.
- Relevant automated tests are added or updated for the client request and the user-visible work-item picker behavior.
- Code is linted.
- All tests are passing.
- No warnings or errors appear in lint or tests.
- No unrelated behavior, migrations, dependencies, or documentation are changed unless this story explicitly requires them.