# Filament UI Review And Modernization Backlog

## Review Summary

The application uses Filament as its shell, but several operator workflows bypass Filament primitives and render their own Blade layouts, Tailwind cards, raw tables, raw inputs, manual badges, and `wire:click` handlers. The result is a UI that looks partly Filament-based but behaves inconsistently across pages and is harder to scan, filter, validate, test, and maintain.

The highest-priority modernization path is to move read-only record pages into Filament `infolist()` definitions, move editable workflows into Filament forms and actions, move repeated related data into relation managers or table widgets, and delete custom Blade views once each page is represented by Filament primitives.

Primary files found during review:

- [app-laravel/app/Filament/Resources/SecurityEventResource/Pages/ViewSecurityEvent.php](../app-laravel/app/Filament/Resources/SecurityEventResource/Pages/ViewSecurityEvent.php) and [app-laravel/resources/views/filament/resources/security-event-resource/pages/view-security-event.blade.php](../app-laravel/resources/views/filament/resources/security-event-resource/pages/view-security-event.blade.php)
- [app-laravel/app/Filament/Resources/AuditLogResource/Pages/ViewAuditLog.php](../app-laravel/app/Filament/Resources/AuditLogResource/Pages/ViewAuditLog.php) and [app-laravel/resources/views/filament/resources/audit-log-resource/pages/view-audit-log.blade.php](../app-laravel/resources/views/filament/resources/audit-log-resource/pages/view-audit-log.blade.php)
- [app-laravel/app/Filament/Pages/OperationsPage.php](../app-laravel/app/Filament/Pages/OperationsPage.php) and [app-laravel/resources/views/filament/pages/operations-page.blade.php](../app-laravel/resources/views/filament/pages/operations-page.blade.php)
- [app-laravel/app/Filament/Pages/PendingSyncPage.php](../app-laravel/app/Filament/Pages/PendingSyncPage.php) and [app-laravel/resources/views/filament/pages/pending-sync-page.blade.php](../app-laravel/resources/views/filament/pages/pending-sync-page.blade.php)
- [app-laravel/app/Filament/Pages/IntegrationSettingsPage.php](../app-laravel/app/Filament/Pages/IntegrationSettingsPage.php) and [app-laravel/resources/views/filament/pages/integration-settings-page.blade.php](../app-laravel/resources/views/filament/pages/integration-settings-page.blade.php)
- [app-laravel/app/Filament/Pages/ProfileIntegrationsPage.php](../app-laravel/app/Filament/Pages/ProfileIntegrationsPage.php), [app-laravel/app/Filament/Pages/SystemCredentialsPage.php](../app-laravel/app/Filament/Pages/SystemCredentialsPage.php), [app-laravel/app/Filament/Pages/Concerns/ManagesIntegrationCredentials.php](../app-laravel/app/Filament/Pages/Concerns/ManagesIntegrationCredentials.php), and [app-laravel/resources/views/filament/pages/integration-credentials-page.blade.php](../app-laravel/resources/views/filament/pages/integration-credentials-page.blade.php)
- [app-laravel/app/Filament/Widgets/OpenAlertsBySourceWidget.php](../app-laravel/app/Filament/Widgets/OpenAlertsBySourceWidget.php) and [app-laravel/resources/views/filament/widgets/open-alerts-by-source.blade.php](../app-laravel/resources/views/filament/widgets/open-alerts-by-source.blade.php)

## Cross-Cutting Constraints

- Use Filament 5 primitives first: resources, tables, columns, filters, actions, forms, infolists, widgets, relation managers, notifications, and schema layout components.
- Do not introduce custom Blade, custom Livewire components, raw HTML, inline CSS, or custom JavaScript for workflows that Filament primitives can express.
- Preserve Fortify authentication, Spatie Permission authorization, policy checks, audit records, and existing routes unless a story explicitly changes a route.
- Use Filament actions with forms and confirmation modals instead of custom `wire:click` buttons.
- Use `TextEntry`, `IconEntry`, `KeyValueEntry`, `RepeatableEntry`, `Section`, `Grid`, `Tabs`, and relation managers instead of hand-written `<dl>`, `<div>` grids, raw `<table>`, and `<pre>` blocks.
- Use `TextColumn`, `IconColumn`, `ToggleColumn`, `SelectColumn`, filters, bulk actions, table groups, and widgets instead of raw table markup.
- Delete obsolete custom Blade files after the equivalent Filament primitive implementation is complete.
- Validate with Docker-based `pint`, `phpstan`, and `pest` before implementation work is considered complete.

## Epic 1: Convert Record Detail Pages To Filament Infolists

### Context

Record detail pages should use `Resource::infolist()` with Filament schema layout and infolist entries. The current alert and audit detail pages extend `ViewRecord` but override `$view` to custom Blade templates that render grids, cards, raw tables, manual badges, raw `<pre>` blocks, and manual action buttons.

### Problem

The most information-dense record pages are not using Filament infolists, so operators see inconsistent spacing, typography, badges, empty states, code payloads, and related data tables. The custom Blade pages are also duplicating behavior that Filament provides through infolists, relation managers, actions, and table primitives.

### High-Level Solution

Move read-only record summaries into resource-level `infolist()` definitions, move related collections into relation managers or table widgets, move commands into Filament actions, and remove custom Blade views that duplicate Filament primitives.

### Child Stories

#### Story 1.1

**Title:** Convert the alert detail summary to a Filament infolist

**Context:** [ViewSecurityEvent.php](../app-laravel/app/Filament/Resources/SecurityEventResource/Pages/ViewSecurityEvent.php) currently points to [view-security-event.blade.php](../app-laravel/resources/views/filament/resources/security-event-resource/pages/view-security-event.blade.php). The Blade view manually renders the alert summary, type-specific sections, remediation, comments, work item links, audit history, attachments, SARIF rows, and raw evidence.

**Problem:** Alert details are difficult to scan because the page is a large custom Blade layout with hand-built labels, grids, badges, tables, buttons, and raw HTML output. It bypasses Filament `Infolist`, `TextEntry`, `KeyValueEntry`, `RepeatableEntry`, `Section`, `Grid`, and `Tabs` primitives.

**Solution:** Define `SecurityEventResource::infolist(Infolist $infolist): Infolist` and render the alert detail page through Filament's standard `ViewRecord` layout without a custom Blade `$view`.

**Implementation Instructions:**

1. Add an `infolist()` method to [SecurityEventResource.php](../app-laravel/app/Filament/Resources/SecurityEventResource.php).
2. Build an `Alert summary` `Section` with a `Grid` containing `TextEntry` fields for title, type, severity, state, source, first seen, last seen, fingerprint, rule ID, tags, and pending sync data.
3. Use `badge()` and explicit `color()` mappings for type, severity, state, source, tags, and pending sync values.
4. Use `placeholder('-')`, `wrap()`, `limit()`, and monospace formatting only through Filament entry APIs where available.
5. Move type-specific fields into `Tabs` or conditional `Section` entries for secret, dependency, code location, and posture details.
6. Render metadata and raw evidence with `KeyValueEntry` instead of raw `<pre>` blocks, preserving redaction behavior currently implemented in `rawEvidencePayload()`.
7. Render remediation as a safe `TextEntry` using sanitized Markdown output only if Filament entry formatting cannot express the content safely; keep HTML input stripped and unsafe links disabled.
8. Remove `protected string $view` from `ViewSecurityEvent` after the infolist fully replaces the Blade page.
9. Delete [view-security-event.blade.php](../app-laravel/resources/views/filament/resources/security-event-resource/pages/view-security-event.blade.php) when no longer referenced.

**Definition of Done:**

- The alert detail page is rendered by `SecurityEventResource::infolist()` and the standard Filament `ViewRecord` page.
- Alert summary, pending sync details, type-specific details, remediation, and raw evidence remain visible with equivalent data.
- Status and enum values use Filament badges with explicit colors.
- Empty values display `-` consistently.
- The obsolete custom Blade alert view is removed.
- Relevant Pest coverage confirms that a user with `alerts.view` can view alert details and that type-specific sections render expected data.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 1.2

**Title:** Replace alert related-data tables with Filament relation managers

**Context:** The alert detail Blade view manually renders related collections for comments, work item links, audit rows, attachments, and SARIF results. [ViewSecurityEvent.php](../app-laravel/app/Filament/Resources/SecurityEventResource/Pages/ViewSecurityEvent.php) contains methods such as `comments()`, `attachments()`, `workItemLinksWithSiblings()`, `auditRows()`, `deleteAttachment()`, `addComment()`, and `unlinkWorkItem()` to support those Blade tables.

**Problem:** Related records are displayed through raw tables and manual buttons instead of Filament relation managers, table columns, actions, bulk actions, and modal forms. This makes row actions, empty states, authorization, confirmation, pagination, and mobile behavior inconsistent.

**Solution:** Add relation managers under `SecurityEventResource` for comments, work item links, attachments, and audit history, and move row operations into Filament table actions.

**Implementation Instructions:**

1. Create `SecurityEventResource\RelationManagers\CommentsRelationManager` using `TextColumn` entries for author, origin, body, and created time.
2. Add `CreateAction` or a custom `Action` with a `Textarea` form for adding local comments, using `CommentManager::add()` and `Notification` for feedback.
3. Add an edit action for editable local comments using `CommentManager::update()` and the existing permission rules.
4. Create `SecurityEventResource\RelationManagers\WorkItemLinksRelationManager` with columns for tracker, work item, state, linked alerts, creator, and created time.
5. Move unlink behavior into a row `Action` with `requiresConfirmation()` and `WorkItemService::unlink()`.
6. Create `SecurityEventResource\RelationManagers\AttachmentsRelationManager` with columns for name, kind, MIME type, size, creator, created time, and actions for download and delete.
7. Move delete behavior into a row `Action` with `requiresConfirmation()` and `AttachmentService::delete()`.
8. Represent audit history through a read-only relation manager or table widget with columns for action, actor, time, and a modal action for payload details.
9. Register the relation managers in `SecurityEventResource::getRelations()`.
10. Remove relation rendering helpers from `ViewSecurityEvent` once all related sections are served by Filament primitives.

**Definition of Done:**

- Alert comments, work item links, attachments, and audit history are displayed through Filament relation managers or table primitives.
- Add, edit, unlink, download, and delete behaviors use Filament actions, modal forms, confirmations, notifications, and authorization checks.
- Raw related-data tables are removed from the alert page.
- Relevant Pest coverage confirms permitted and forbidden users see the correct relation actions.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 1.3

**Title:** Convert audit log detail records to a Filament infolist

**Context:** [ViewAuditLog.php](../app-laravel/app/Filament/Resources/AuditLogResource/Pages/ViewAuditLog.php) overrides `$view` and uses [view-audit-log.blade.php](../app-laravel/resources/views/filament/resources/audit-log-resource/pages/view-audit-log.blade.php) to render a custom `<dl>` and raw payload `<pre>`.

**Problem:** Audit details bypass Filament infolist entries, link actions, badge colors, and key/value display. The custom payload block also has styling and behavior that differs from the rest of the Filament application.

**Solution:** Define `AuditLogResource::infolist()` and remove the custom audit log Blade view.

**Implementation Instructions:**

1. Add `infolist(Infolist $infolist): Infolist` to [AuditLogResource.php](../app-laravel/app/Filament/Resources/AuditLogResource.php).
2. Create an `Audit record` `Section` with `TextEntry` entries for timestamp, actor kind, action, user, IP address, subject type, and subject ID.
3. Use `badge()` with explicit colors for actor kind.
4. Use `url()` on user and subject entries when `ViewAuditLog::getUserUrl()` or `getSubjectUrl()` resolves a target.
5. Create a `Payload` `Section` with `KeyValueEntry` from the redacted payload array.
6. Keep the existing redaction behavior but return structured arrays for the infolist instead of a JSON string where possible.
7. Remove `protected string $view` from `ViewAuditLog` when the infolist is complete.
8. Delete [view-audit-log.blade.php](../app-laravel/resources/views/filament/resources/audit-log-resource/pages/view-audit-log.blade.php).

**Definition of Done:**

- Audit log details render through `AuditLogResource::infolist()`.
- Payload values remain redacted and inspectable.
- User and subject links still resolve when the target records exist.
- The obsolete custom audit Blade view is removed.
- Relevant Pest coverage confirms audit detail visibility, redaction, and links.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 1.4

**Title:** Add infolists to inventory resources

**Context:** [SoftwareSystemResource.php](../app-laravel/app/Filament/Resources/SoftwareSystemResource.php), [SecurityContainerResource.php](../app-laravel/app/Filament/Resources/SecurityContainerResource.php), and [SoftwareSystemLinkResource.php](../app-laravel/app/Filament/Resources/SoftwareSystemLinkResource.php) define tables and relation managers, but their `ViewRecord` pages do not define a resource-level infolist.

**Problem:** Inventory detail pages rely mostly on relation managers without a standard summary section, so operators must infer record context from relation tabs and table rows.

**Solution:** Add concise resource-level infolists for software systems, containers, and system links.

**Implementation Instructions:**

1. Add `infolist()` to `SoftwareSystemResource` with summary entries for name, source, open event counts, critical/high/medium counts, and updated time.
2. Add `infolist()` to `SecurityContainerResource` with entries for name, kind, software system, open events, first seen if available, last seen, and updated time.
3. Add `infolist()` to `SoftwareSystemLinkResource` with entries for name, description, member count, created time, and updated time.
4. Use `Section`, `Grid`, `TextEntry`, `badge()`, `color()`, `placeholder('-')`, and `since()` consistently.
5. Keep existing relation managers registered and visible below the infolist.

**Definition of Done:**

- Software system, container, and system link detail pages have clear Filament infolist summaries.
- Existing relation managers remain available.
- Empty values display `-` consistently.
- Relevant Pest coverage confirms each view page renders summary fields for authorized users.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

## Epic 2: Convert Admin And Sync Workflows To Filament Tables, Forms, And Actions

### Context

Admin and sync pages currently use custom Blade layouts for workflows that map directly to Filament tables, forms, actions, bulk actions, and widgets.

### Problem

Operators encounter different controls and layouts depending on the page: raw checkboxes, custom number inputs, raw selects, custom cards, manual save buttons, and hand-built status messages. This weakens accessibility, validation, authorization consistency, and data density.

### High-Level Solution

Model operational workflows as Filament pages implementing `HasTable`, resources where a database model exists, modal actions for edits and commands, and widgets for dashboard-like summaries.

### Child Stories

#### Story 2.1

**Title:** Rebuild pending sync review as a Filament table with bulk actions

**Context:** [PendingSyncPage.php](../app-laravel/app/Filament/Pages/PendingSyncPage.php) uses [pending-sync-page.blade.php](../app-laravel/resources/views/filament/pages/pending-sync-page.blade.php) to render a custom form, source sections, raw checkboxes, alert cards, and a submit button.

**Problem:** Pending sync review duplicates Filament table selection and bulk action behavior. Operators cannot use standard table sorting, filters, search, pagination, or bulk action affordances.

**Solution:** Convert `PendingSyncPage` to a Filament table page with grouped rows and a `Push to source` bulk action.

**Implementation Instructions:**

1. Make `PendingSyncPage` implement `HasTable` and use `InteractsWithTable`.
2. Build the table query from pending `SecurityEvent` records represented by `PendingSyncQuery` behavior.
3. Add columns for source, title, current state, pending state, current severity, pending severity, last editor, last edited time, pending comment, last error, and retry count.
4. Use `TextColumn::badge()` with explicit colors for current and pending state/severity.
5. Add filters for source, pending state, pending severity, last error present, and last editor where data is available.
6. Add a `BulkAction::make('pushToSource')` with `requiresConfirmation()` that dispatches `PushEventStatesJob` grouped by source.
7. Preserve authorization through `canAccess()`, `Gate::authorize('work-items.sync')`, and `Gate::authorize('sources.push-state')`.
8. Delete [pending-sync-page.blade.php](../app-laravel/resources/views/filament/pages/pending-sync-page.blade.php) after the page renders through Filament table primitives.

**Definition of Done:**

- Pending sync records render in a Filament table with selectable rows.
- Pushing selected alerts is a Filament bulk action with confirmation and notifications.
- Operators can filter and sort pending sync data using table controls.
- The obsolete pending sync Blade view is removed.
- Relevant Pest coverage confirms row visibility, bulk action dispatch, empty selection handling, and authorization.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 2.2

**Title:** Rebuild integration settings as a Filament resource-style table

**Context:** [IntegrationSettingsPage.php](../app-laravel/app/Filament/Pages/IntegrationSettingsPage.php) uses [integration-settings-page.blade.php](../app-laravel/resources/views/filament/pages/integration-settings-page.blade.php) to render a raw table with raw checkboxes, number inputs, selects, status text, and custom Save/Test buttons.

**Problem:** Integration settings bypass Filament table columns, form fields, row actions, validation presentation, and loading behavior. Dense integration state is hard to scan and edit consistently.

**Solution:** Represent integrations through a Filament table with status columns and row actions for edit, save, test connection, and Jira default project configuration.

**Implementation Instructions:**

1. Convert `IntegrationSettingsPage` to implement `HasTable` and use `InteractsWithTable`, or create an `IntegrationSettingResource` if the workflow maps cleanly to `IntegrationSetting` records.
2. Use `TextColumn` for kind, integration display name, integration ID, started time, last sync time, and last sync message.
3. Use `TextColumn::badge()` with explicit colors for enabled status and last sync status.
4. Use a Filament `Action::make('editSettings')` with a form containing `Toggle`, `TextInput` for interval, and `Select` for service user.
5. Use a Filament `Action::make('testConnection')` for connection tests, with result communicated through `Notification` and visible status columns.
6. Move Jira default project key into the edit settings action form for the Jira tracker row.
7. Preserve `IntegrationSettingsRepository`, `TrackerConfigRepository`, `Vault::runAsOwner()`, audit recording, and existing authorization.
8. Delete [integration-settings-page.blade.php](../app-laravel/resources/views/filament/pages/integration-settings-page.blade.php) after the table and actions fully replace the raw table.

**Definition of Done:**

- Integration settings render in a Filament table or resource using table columns and row actions.
- Enablement, interval, service user, Jira default project key, and connection test behavior remain available.
- Raw checkboxes, inputs, selects, and buttons are removed from the page.
- The obsolete integration settings Blade view is removed.
- Relevant Pest coverage confirms settings updates, validation errors, connection tests, audit records, and authorization.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 2.3

**Title:** Rebuild integration credential management with Filament forms and actions

**Context:** [ProfileIntegrationsPage.php](../app-laravel/app/Filament/Pages/ProfileIntegrationsPage.php) and [SystemCredentialsPage.php](../app-laravel/app/Filament/Pages/SystemCredentialsPage.php) share [ManagesIntegrationCredentials.php](../app-laravel/app/Filament/Pages/Concerns/ManagesIntegrationCredentials.php) and render [integration-credentials-page.blade.php](../app-laravel/resources/views/filament/pages/integration-credentials-page.blade.php). The Blade view manually renders field labels, password inputs, text inputs, textareas, replace buttons, status badges, and save/test buttons.

**Problem:** Credential management handles sensitive workflows through raw Blade controls instead of Filament form fields, actions, validation display, and section layout. The replace-secret state is custom UI that should be expressed with framework form fields and actions.

**Solution:** Convert credential pages to Filament form schemas generated from `CredentialField` definitions, with page/header actions for save all and test all, and per-integration actions for save and test.

**Implementation Instructions:**

1. Replace manual form markup with Filament schema components: `Section`, `Grid`, `TextInput`, `Textarea`, `Placeholder`, `Toggle`, and `Action` where appropriate.
2. Generate each integration's credential fields from `CredentialField` metadata inside PHP, not Blade loops.
3. Use `TextInput::password()` for secret values and clear helper text for stored-secret replacement state.
4. Use a Filament action or toggle to enter replacement mode for existing secrets.
5. Use page header actions for `Test all configured` and `Save all changes`.
6. Use per-section actions for `Test` and `Save credentials`.
7. Preserve `Vault`, credential owner selection, notifications, and validation behavior.
8. Delete [integration-credentials-page.blade.php](../app-laravel/resources/views/filament/pages/integration-credentials-page.blade.php) after both profile and system credential pages are represented by Filament primitives.

**Definition of Done:**

- Profile and system credential pages render credential forms through Filament schema components.
- Secret replacement, descriptions, save, save all, test, and test all behaviors remain available.
- Raw inputs, textareas, buttons, and manual badges are removed from credential pages.
- The obsolete credential Blade view is removed.
- Relevant Pest coverage confirms personal and system credential save/test behavior, secret replacement validation, and authorization.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 2.4

**Title:** Rebuild operations dashboards with Filament widgets and table widgets

**Context:** [OperationsPage.php](../app-laravel/app/Filament/Pages/OperationsPage.php) already contains a Filament failed-jobs table and header actions, but [operations-page.blade.php](../app-laravel/resources/views/filament/pages/operations-page.blade.php) renders custom KPI cards, schedule rows, recent sync run cards, and recent error cards.

**Problem:** Operational status data is split between one Filament table and several custom Blade card layouts. The custom cards do not provide standard table scanning, badges, empty states, pagination, or action affordances.

**Solution:** Keep the failed-jobs table as a Filament table and replace the custom Blade status sections with Filament widgets.

**Implementation Instructions:**

1. Create an `OperationsHealthStatsWidget` extending `StatsOverviewWidget` for queued jobs, failed jobs, running syncs, and managed schedule entry count.
2. Create a `ManagedScheduleTableWidget` extending `TableWidget` or represent schedule entries through a table on the page if records are static.
3. Create an operations-specific `RecentSyncRunsTableWidget` or reuse the existing dashboard widget with operations-appropriate authorization.
4. Create a `RecentErrorsTableWidget` extending `TableWidget` with columns for channel, message preview, occurred time, and link to error logs if available.
5. Register widgets on `OperationsPage` using Filament page widget APIs.
6. Keep existing header actions for dispatching integrations, fetching sources, refreshing trackers, reconciling work items, and maintenance.
7. Delete [operations-page.blade.php](../app-laravel/resources/views/filament/pages/operations-page.blade.php) after the page layout is fully represented by Filament page content and widgets.

**Definition of Done:**

- Operations health, schedule entries, recent sync runs, and recent errors render through Filament widgets or table widgets.
- Failed jobs remain a Filament table with retry, forget, and details actions.
- Custom operational cards are removed.
- The obsolete operations Blade view is removed.
- Relevant Pest coverage confirms widget visibility and header action authorization for admin users.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

## Epic 3: Standardize Dashboard And Table Experiences

### Context

Some dashboard components already use Filament widgets, but one widget still uses a custom Blade table. Several resource tables are functional but need consistency improvements for placeholders, badges, colors, filters, grouping, and dense scanning.

### Problem

Mixed widget implementations and uneven table details make the application feel non-standard even on pages that mostly use Filament primitives.

### High-Level Solution

Use Filament widget and table primitives everywhere, tighten table states, and complete filters/actions that currently expose poor UX.

### Child Stories

#### Story 3.1

**Title:** Convert open alerts by source to a Filament table widget

**Context:** [OpenAlertsBySourceWidget.php](../app-laravel/app/Filament/Widgets/OpenAlertsBySourceWidget.php) extends `Widget` and renders [open-alerts-by-source.blade.php](../app-laravel/resources/views/filament/widgets/open-alerts-by-source.blade.php), which contains a raw table and hand-styled links.

**Problem:** The dashboard mixes standard `StatsOverviewWidget`, `ChartWidget`, and `TableWidget` components with one custom Blade widget. This creates inconsistent table styling and behavior.

**Solution:** Replace `OpenAlertsBySourceWidget` with a `TableWidget` using Filament columns and URLs.

**Implementation Instructions:**

1. Change `OpenAlertsBySourceWidget` to extend `TableWidget`.
2. Use `records(fn () => DashboardData::openAlertsBySourceAndWorkItemState())` or a query-backed table if a query is available.
3. Add `TextColumn` columns for source, with work item count, and without work item count.
4. Use `badge()` and `url()` on source and count columns to preserve navigation to filtered alert lists.
5. Use `emptyStateDescription('No open alerts.')`.
6. Remove `getViewData()` if no longer needed.
7. Delete [open-alerts-by-source.blade.php](../app-laravel/resources/views/filament/widgets/open-alerts-by-source.blade.php).

**Definition of Done:**

- Open alerts by source renders as a Filament `TableWidget`.
- Source and count links still navigate to filtered alert lists.
- The obsolete widget Blade view is removed.
- Relevant Pest coverage confirms the widget returns expected rows and URLs for authorized users.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 3.2

**Title:** Complete and polish alert list filters

**Context:** [SecurityEventResource.php](../app-laravel/app/Filament/Resources/SecurityEventResource.php) defines a rich alert table, but the `work_item` filter has an empty form while its query expects `tracker_id` and `work_item_id` values.

**Problem:** Operators cannot enter work item filter values even though the query code expects them. This makes tracker-based alert review harder and creates a broken filter affordance.

**Solution:** Replace the empty work item filter form with Filament form fields and indicators.

**Implementation Instructions:**

1. Add a `Select::make('tracker_id')` field populated from the tracker registry.
2. Add a `TextInput::make('work_item_id')` field with a clear label and max length.
3. Keep the existing `SecurityEventTableQuery::applyWorkItem()` query behavior.
4. Update `indicateUsing()` so tracker-only and work-item-only states are represented accurately if both fields are not required.
5. Add placeholders to table columns where null values currently render blank.
6. Review `work_item_state`, `source_id`, `type`, date, and count columns for consistent `badge()`, `color()`, `placeholder('-')`, `toggleable()`, and `sortable()` behavior.

**Definition of Done:**

- The work item filter exposes usable Filament form fields.
- Filter indicators accurately describe active work item filters.
- Alert table columns have consistent placeholders and badge colors.
- Relevant Pest coverage confirms work item filtering behavior.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 3.3

**Title:** Apply table readability standards across resources and relation managers

**Context:** Existing resources use Filament tables, but column treatment varies across [SecurityEventResource.php](../app-laravel/app/Filament/Resources/SecurityEventResource.php), [SoftwareSystemResource.php](../app-laravel/app/Filament/Resources/SoftwareSystemResource.php), [SecurityContainerResource.php](../app-laravel/app/Filament/Resources/SecurityContainerResource.php), [SoftwareSystemLinkResource.php](../app-laravel/app/Filament/Resources/SoftwareSystemLinkResource.php), [AuditLogResource.php](../app-laravel/app/Filament/Resources/AuditLogResource.php), and relation managers under [app-laravel/app/Filament/Resources](../app-laravel/app/Filament/Resources).

**Problem:** Some tables lack consistent placeholders, badge colors, wrapping, toggleable secondary columns, recency formatting, and action grouping. This makes data harder to read at scale.

**Solution:** Apply a single Filament table readability standard across resource tables and relation managers.

**Implementation Instructions:**

1. Ensure all enum/status columns use `badge()` and explicit `color()` mappings.
2. Ensure nullable columns use `placeholder('-')`.
3. Use `wrap()` and `limit()` on long text columns such as titles, descriptions, messages, exceptions, and payload previews.
4. Use `since()` for recency-oriented date columns and `dateTime()` for exact audit/compliance timestamps.
5. Mark secondary columns `toggleable(isToggledHiddenByDefault: true)` where they add detail but reduce scan density.
6. Use `ActionGroup` when a table row has more than three actions.
7. Add or tighten default sorting and pagination options where missing.

**Definition of Done:**

- Resource and relation manager tables follow consistent Filament column patterns.
- Dense tables are easier to scan without losing detail.
- Row actions are grouped where appropriate.
- Relevant Pest coverage confirms important table columns and actions still render for authorized users.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

## Epic 4: Remove Custom Filament Blade And Enforce Primitive-First UI Rules

### Context

Custom Filament Blade views under [app-laravel/resources/views/filament](../app-laravel/resources/views/filament) are the main source of non-standard UI. Once the preceding epics migrate each workflow, those views should disappear or be reduced to unavoidable minimal shells.

### Problem

Without cleanup and guardrails, future UI changes may add more custom Blade and reintroduce inconsistent Filament experiences.

### High-Level Solution

Delete obsolete custom Blade views, document primitive-first expectations, and add tests or static checks that catch new custom Filament Blade usage when a primitive should be used.

### Child Stories

#### Story 4.1

**Title:** Delete obsolete custom Filament Blade views

**Context:** The review found custom Filament Blade views for alert details, audit details, operations, pending sync, integration settings, integration credentials, and the open alerts dashboard widget.

**Problem:** These views duplicate Filament primitives and keep the application visually inconsistent.

**Solution:** Remove each custom Filament Blade view after its replacement story is complete and no PHP class references it.

**Implementation Instructions:**

1. Confirm no PHP class references each target Blade view before deletion.
2. Delete obsolete files under [app-laravel/resources/views/filament](../app-laravel/resources/views/filament) only after equivalent Filament primitives are implemented.
3. Keep only unavoidable Filament Blade views that cannot be expressed with resources, pages, forms, tables, infolists, actions, or widgets.
4. For any remaining custom Filament Blade view, add a short PHP-side comment near the `$view` property explaining which Filament primitive is insufficient.

**Definition of Done:**

- All obsolete custom Filament Blade views are removed.
- Remaining custom Filament Blade views are justified by a specific primitive gap.
- No page or widget references a deleted view.
- Relevant route/page smoke tests pass.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 4.2

**Title:** Add a Filament primitive compliance check

**Context:** The codebase already has project rules that require framework-first Filament usage, but custom Blade can still be added without a targeted check.

**Problem:** Primitive-first UI rules depend on manual review and can regress over time.

**Solution:** Add a lightweight test that reports custom Filament Blade usage and forbidden raw controls in Filament views.

**Implementation Instructions:**

1. Add a Pest test under [app-laravel/tests](../app-laravel/tests) that scans [app-laravel/resources/views/filament](../app-laravel/resources/views/filament).
2. Fail when a Filament Blade view contains raw `<table>`, `<input>`, `<select>`, `<textarea>`, inline `class=` utility-heavy markup, `wire:click`, or `wire:submit`, unless the file is explicitly allow-listed with a documented reason.
3. Keep the allow-list empty after Epics 1 through 3 are complete unless an unavoidable primitive gap remains.
4. Include failure output that names the file and the forbidden pattern.

**Definition of Done:**

- A Pest test detects custom Filament Blade patterns that should use primitives.
- The test is documented enough for future implementers to understand the rule.
- The allow-list is empty or contains only justified exceptions.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

#### Story 4.3

**Title:** Align authentication views with the Filament application shell

**Context:** Authentication Blade views exist under [app-laravel/resources/views/auth](../app-laravel/resources/views/auth) for login and two-factor flows. Fortify must remain the source of authentication and mandatory 2FA behavior.

**Problem:** Authentication screens can visually diverge from the Filament application if they continue to use independent custom styling.

**Solution:** Review the Fortify authentication views and align their visual treatment with Filament-supported authentication page patterns while preserving Fortify behavior.

**Implementation Instructions:**

1. Keep Fortify routes, validation, session handling, and 2FA behavior unchanged.
2. Prefer Filament panel authentication pages or Filament-compatible form components where the framework supports the required Fortify flow.
3. Remove unrelated Laravel starter-page styling from authentication views.
4. Ensure login, two-factor setup, recovery code, and challenge flows use consistent labels, buttons, validation errors, and spacing.
5. Add feature tests for login and mandatory 2FA flow rendering after the visual alignment.

**Definition of Done:**

- Authentication screens visually align with the Filament application shell.
- Fortify remains responsible for authentication and 2FA behavior.
- No authentication, session, CSRF, password, or 2FA security behavior is reimplemented.
- Relevant Fortify and 2FA feature tests pass.
- Lint checks pass without warnings or errors.
- Relevant test suites pass without warnings or errors.
- No unrelated behavior, migrations, dependencies, or documentation are changed.
- Code is linted.
- All tests are passing.
- No warnings or errors in lint or tests.

## Recommended Implementation Order

1. Epic 1, Story 1.1: Alert detail infolist. This removes the most visible and most complex custom UI.
2. Epic 1, Story 1.2: Alert relation managers. This removes the largest set of raw tables and manual buttons.
3. Epic 2, Story 2.1: Pending sync table. This gives sync operators standard bulk review controls.
4. Epic 2, Stories 2.2 and 2.3: Integration settings and credentials. These remove raw forms from sensitive admin workflows.
5. Epic 2, Story 2.4 and Epic 3, Story 3.1: Operations and dashboard widgets. These make the dashboard/admin surface consistently Filament.
6. Epic 1, Stories 1.3 and 1.4 plus Epic 3 polish stories. These standardize secondary record views and tables.
7. Epic 4 cleanup and compliance checks. Run after custom Blade replacements are complete.
