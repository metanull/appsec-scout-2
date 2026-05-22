# Plan Role Guide

## Purpose

Plan users turn alerts into Jira or GitHub work items and maintain the local link state.

## Typical Workflow

1. Open an alert or select multiple alerts from the table.
2. Create a work item for one alert or a grouped work item for several alerts.
3. Choose the target tracker, project, item type, and optional metadata.
4. Alternatively, link an existing tracker work item.
5. Review the linked item state and badges in the alert UI.

## What Plan Adds

- create a tracker work item
- create grouped work items
- link an existing work item
- review tracker refresh data written back locally

## Important Constraint

Planning actions update the tracker and local link records, but they do not push alert state changes back to upstream sources.

## Credential Model

Tracker actions use the active credential resolution flow:

- personal credential when the user has one
- integration service user if configured for that tracker
- system credential otherwise