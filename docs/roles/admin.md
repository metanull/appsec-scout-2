# Admin Role Guide

## Purpose

Admin users operate the application itself rather than only working alerts.

## Typical Workflow

1. Bootstrap the first admin account with `appsec:bootstrap-admin`.
2. Sign in and complete mandatory 2FA.
3. Create users and assign cumulative roles.
4. Configure system credentials.
5. Configure integrations, service users, and polling intervals.
6. Use the Operations page to inspect queues, retry failed jobs, and run one-off maintenance actions.
7. Review audit and error logs for operator and runtime visibility.

## Admin Surfaces

- `Admin -> Users`
- `Admin -> System credentials`
- `Admin -> Integrations`
- `Admin -> Operations`
- `Admin -> Audit Log`
- `Admin -> Errors`
- `Admin -> System links`

## Key Responsibilities

- keep user access current
- disable unused or compromised accounts
- reset 2FA when needed
- manage integration service-user ownership
- review failed jobs and sync failures
- ensure health endpoint, queue workers, and scheduler continue running

## Guardrails

- Admin is still subject to mandatory 2FA
- all write actions are audited
- disabling a user immediately invalidates active sessions
- Defender-specific administration is not part of M6