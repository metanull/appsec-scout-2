# Admin Role Guide

## Purpose

Admin users operate the application itself, in addition to holding every permission every other
role has.

## Typical Workflow

1. Bootstrap the first admin account with `appsec:bootstrap-admin`.
2. Sign in and complete mandatory multi-factor enrollment.
3. Create users and assign cumulative roles.
4. Configure system credentials.
5. Configure integrations and polling intervals.
6. Use the Operations page to inspect queues, retry failed jobs, reconcile tracker links, sync
   inventory, and run one-off maintenance actions.
7. Review audit and error logs for operator and runtime visibility.

## Admin Surfaces

- `Admin -> Users`
- `Admin -> System Credentials`
- `Admin -> Operations`
- `Admin -> Audit Log`
- `Admin -> Errors`
- `Admin -> Repository Providers` (also reachable by Plan and Sync, via `admin.repository-providers`)
- `Admin -> Pending Sync` (also reachable by Sync, via `work-items.sync` + `sources.push-state`)
- Every Reader-group resource (Alerts, Software Systems, Containers, Software Assets, Dependencies,
  Local Findings), plus every Triage/Plan/Sync capability on them.

## Key Responsibilities

- Keep user access current.
- Disable unused or compromised accounts.
- Reset multi-factor enrollment when needed.
- Review failed jobs and sync failures.
- Ensure the health endpoint, queue workers, and scheduler continue running.

## Guardrails

- Admin is still subject to mandatory multi-factor authentication.
- All write actions are audited.
- Disabling a user immediately invalidates their active session.
- Defender for Cloud has no administration surface — see
  [docs/concepts/sources-trackers-source-control.md](../concepts/sources-trackers-source-control.md#supported-vs-deferred).
