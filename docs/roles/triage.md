# Triage Role Guide

## Purpose

Triage users classify and document alerts locally inside AppSec Scout.

## Typical Workflow

1. Open an alert from `Reader -> Alerts`.
2. Change the pending state with a justification comment.
3. Change the pending severity when needed.
4. Add or edit comments for investigation context.
5. Use supported triage commands where appropriate.
6. Leave the alert marked dirty until a Sync operator pushes the change upstream.

## What Triage Adds

- change alert state
- change alert severity
- add comments
- run supported triage commands
- bulk state changes from the alerts table

## Important Constraint

Triage actions only change local state. They do not update upstream sources directly.

## Expected Signals

- dirty alerts remain marked pending until sync occurs
- comments and state transitions create audit rows
- failures create visible error records rather than being silently ignored