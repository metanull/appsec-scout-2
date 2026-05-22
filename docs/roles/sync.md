# Sync Role Guide

## Purpose

Sync users are the only operators who propagate local alert changes back to upstream sources.

## Typical Workflow

1. Open `Sync -> Pending Sync`.
2. Review the grouped dirty alerts.
3. Select one or more alerts that are ready for upstream update.
4. Trigger push for the selected alerts.
5. Confirm that dirty flags clear on success or that retry metadata is written on failure.

## What Sync Adds

- view pending sync groups
- queue source push jobs for dirty alerts
- observe sync success and failure state in local data

## Important Constraint

Sync is the only supported path that mutates upstream alert state or comments.

## Failure Behavior

On push failure:

- the alert remains dirty
- retry metadata is recorded in event metadata
- an error record is written
- an audit row records the failed push attempt