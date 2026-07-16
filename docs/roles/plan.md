# Plan Role Guide

## Purpose

Plan users turn alerts and local findings into Jira or GitHub work items, curate Software Assets,
and maintain the local link state.

## Typical Workflow

1. Open an alert (or select multiple alerts from the table), or a Local Finding.
2. Create a work item for one item, or a grouped work item for several alerts.
3. Choose the target tracker, project, item type, and optional metadata.
4. Alternatively, link an existing tracker work item.
5. Review the linked item state and badges in the item's UI.
6. Curate Software Assets and Repository Providers as needed (see below).

## What Plan Adds

- Create a tracker work item, including grouped work items for several alerts.
- Link an existing work item.
- The same create/link/unlink actions on a Local Finding (`LocalFindingResource`), via its own
  `LocalFindingWorkItemLink` records.
- Review tracker refresh data written back locally.
- Create and edit Software Assets (`context.curate`), and attach/detach a Software System to one.
- Add Curated Links on any Asset/System/Container/Alert (`context.curate`).
- Manage Repository Providers (`admin.repository-providers`) — the base URL/type configuration
  Repository Mappings resolve against, even though this surface lives under the Admin navigation
  group in Filament.

## Important Constraint

Planning actions update the tracker and local link records, but they do not push alert state
changes back to upstream sources — only a Sync operator's push does that.

## Credential Model

Tracker actions taken interactively by a Plan operator resolve that operator's own personal
tracker credential (`Profile -> Integrations`). If no personal credential is configured, the
action fails with a clear error.
