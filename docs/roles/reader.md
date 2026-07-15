# Reader Role Guide

## Purpose

Reader users consume the local AppSec Scout dataset without mutating it.

## Typical Workflow

1. Sign in at `http://localhost:8080/`.
2. Complete multi-factor enrollment if prompted.
3. Review dashboard widgets and recent sync activity.
4. Open `Reader -> Alerts` to inspect the alert list.
5. Open an alert detail page to review state, severity, metadata, comments, tracker links, and attachments.
6. Review `Reader -> Software Systems`, `Reader -> Containers`, and `Reader -> Software Assets` for
   surrounding asset context, and `Reader -> Dependencies`/`Reader -> Local Findings` for
   SBOM/static-analysis results.

## What Reader Can Do

- View alerts (`SecurityEventResource`).
- View software systems, containers, and software assets.
- View Dependencies (`SoftwareComponentResource`) and Local Findings (`LocalFindingResource`) —
  the SBOM/SARIF-derived counterpart to alerts, read-only for Reader.
- Inspect linked tracker metadata.
- Inspect audit-derived sync context shown in pages.

## What Reader Cannot Do

- Change state or severity (on an Alert or a Local Finding).
- Add comments.
- Create or link work items.
- Push local changes upstream.
- Access Admin pages.

## Notes

Reader access is the baseline role and is automatically assigned to new users when no explicit
role is selected.
