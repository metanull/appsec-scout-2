# Sync Role Guide

## Purpose

Sync users are the only operators who propagate local alert changes back to upstream sources.
Sync also inherits every Plan-level capability.

## Typical Workflow

1. Open `Admin -> Pending Sync`.
2. Review the grouped dirty alerts.
3. Select one or more alerts that are ready for upstream update.
4. Trigger push for the selected alerts.
5. Confirm that dirty flags clear on success or that retry metadata is written on failure.

## What Sync Adds

- View pending sync groups (`Admin -> Pending Sync`, requires both `work-items.sync` and
  `sources.push-state`).
- Queue source push jobs for dirty alerts.
- Observe sync success and failure state in local data.
- Access `Admin -> Operations` (gated by `admin.queue` **or** `work-items.sync`, and Sync holds
  `work-items.sync`): dispatch due integrations, fetch one source, refresh one tracker, reconcile
  all tracker links, prune audit/error logs, and retry/forget failed jobs. The one Operations
  action Sync cannot use is "Sync inventory," gated by `admin.queue` alone.
- Everything Plan can do: create/link tracker work items (on alerts and on Local Findings), curate
  Software Assets, manage Repository Providers.

## Important Constraint

Sync is the only role that mutates upstream alert state or comments. Pushing always runs under the
system credential, never the triggering user's own token.

## Failure Behavior

On push failure:

- The alert remains dirty.
- Retry metadata is recorded in event metadata (up to 3 attempts).
- An error record is written.
- An audit row records the failed push attempt.
