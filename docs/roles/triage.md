# Triage Role Guide

## Purpose

Triage users classify and document alerts and local findings inside AppSec Scout.

## Typical Workflow

1. Open an alert from `Reader -> Alerts`, or a scan result from `Reader -> Local Findings`.
2. Change the pending state with a justification comment.
3. Change the pending severity when needed.
4. Add or edit comments for investigation context.
5. Leave the alert marked dirty until a Sync operator pushes the change upstream.

## What Triage Adds

- Change alert state and severity (staged as `pending_state`/`pending_severity`, pushed later by
  Sync).
- Change a Local Finding's status and severity override — the identical action, immediately
  applied (there's nothing upstream to push a Local Finding change to).
- Add comments on alerts and on Local Findings.
- Bulk state changes from the alerts table.

## Important Constraint

Triage actions on alerts only change local state — they never update an upstream source directly,
only a Sync operator's push does that. Local Finding changes are always local; there is no
upstream Source for a Local Finding to push to in the first place.

## Expected Signals

- Dirty alerts remain marked pending until a Sync push occurs.
- Comments and state transitions create audit rows.
- Failures create visible error records rather than being silently ignored.
