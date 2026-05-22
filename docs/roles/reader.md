# Reader Role Guide

## Purpose

Reader users consume the local AppSec Scout dataset without mutating it.

## Typical Workflow

1. Sign in at `http://localhost:8080/`.
2. Complete two-factor setup if prompted.
3. Review dashboard widgets and recent sync activity.
4. Open `Reader -> Alerts` to inspect the alert list.
5. Open an alert detail page to review state, severity, metadata, comments, tracker links, and attachments.
6. Review `Reader -> Containers` and `Reader -> Systems` for surrounding asset context.

## What Reader Can Do

- view alerts
- view software systems
- view containers
- inspect linked tracker metadata
- inspect audit-derived sync context shown in pages

## What Reader Cannot Do

- change state or severity
- add comments
- create or link work items
- push local changes upstream
- access Admin pages

## Notes

Reader access is the baseline role and is automatically assigned to new users when no explicit role is selected.