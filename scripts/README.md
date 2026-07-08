# scripts/

PowerShell entry points for developing, running, and operating AppSec Scout. All scripts are meant to be run from the repository root (they resolve their own root via `$MyInvocation`).

| Script | Purpose |
|--------|---------|
| [appsec-scout.ps1](#appsec-scoutps1) | Start/rebuild the application stack |
| [invoke-check.ps1](#invoke-checkps1) | Run CI checks (lint, tests, static analysis, dependencies) |
| [invoke-fix.ps1](#invoke-fixps1) | Run mutating auto-fixes (lint, dependency updates) |
| [invoke-claude.ps1](#invoke-claudeps1) | Run Claude Code in a sandboxed container against this repo |
| [invoke-ops.ps1](#invoke-opsps1) | Open an appsec-ops shell, or run an org-wide SBOM/vuln/secret scan |
| [test-GitHubToken.ps1](#test-githubtokenps1) | Validate a GitHub PAT |
| [test-AzureDevOpsToken.ps1](#test-azuredevopstokenps1) | Validate an Azure DevOps PAT |
| [validate-workflows.cjs](#validate-workflowscjs) | Lint GitHub Actions workflow YAML |

## appsec-scout.ps1

Starts the app via Docker Compose: rebuilds the `app` image (respecting Docker's layer cache, so this is fast when nothing changed), brings up containers, waits for `/up` to respond, runs migrations/seeders, and opens the app in the browser. Every run rebuilds the image — a plain `.\scripts\appsec-scout.ps1` is enough to pick up source, dependency, or Dockerfile changes; you never need `-Rebuild` just to avoid a stale container.

**Parameters**
- `-Rebuild` — stop/remove containers, volumes, and orphans (wipes the database and all app state) and re-exports host CA certs before rebuilding and starting fresh (also re-imports `.credentials.json` if present). Use this for a clean slate, not for routine restarts.
- `-Force` — skip Docker's build cache for the app image (`--no-cache`). Independent of `-Rebuild` — use it alone if you suspect a stale cache layer, without wiping any data.

```powershell
.\scripts\appsec-scout.ps1                 # rebuild (cache permitting) + start, preserving data
.\scripts\appsec-scout.ps1 -Force          # rebuild from scratch (no cache) + start, preserving data
.\scripts\appsec-scout.ps1 -Rebuild        # wipe all data, re-export certs, rebuild + start fresh
.\scripts\appsec-scout.ps1 -Rebuild -Force
```

## invoke-check.ps1

Runs read-only CI checks against the Laravel app inside the `app` container.

**Parameters**
- `-Check <all|lint|test|test-sqlite|test-mysql|static-analysis|smoke|dependencies|npm-audit>` — default `all`. `test` runs both `test-sqlite` and `test-mysql`.

```powershell
.\scripts\invoke-check.ps1
.\scripts\invoke-check.ps1 -Check lint
.\scripts\invoke-check.ps1 -Check test-mysql
```

## invoke-fix.ps1

Runs mutating fix operations (formatting, dependency updates) — kept separate from `invoke-check.ps1` so checks stay read-only.

**Parameters**
- `-Fix <all|lint-fix|dependencies-fix|npm-audit-fix|npm-update>` — default `all`.

```powershell
.\scripts\invoke-fix.ps1
.\scripts\invoke-fix.ps1 -Fix lint-fix
```

## invoke-claude.ps1

Runs Claude Code in an isolated, ephemeral container with no access to the host filesystem — either interactively, for one-time login, or as an autonomous task that clones a repo, does the work, and opens a PR. Every run rebuilds the `claude` image first (respecting Docker's layer cache, so this is fast when nothing changed) — you never need `-Rebuild` just to pick up a Dockerfile/entrypoint change.

**Parameters**
- `-Mode <shell|login|task>` — default `shell`.
- `-Task <string>` — task prompt; required with `-Mode task`.
- `-Repo <url>` — repo to clone; overrides `CLAUDE_REPO_URL`.
- `-Branch <name>` — branch to clone/PR against; overrides `CLAUDE_REPO_BRANCH`.
- `-Name <string>` — git commit display name; overrides `GIT_USER_NAME`.
- `-Credential <PSCredential>` — `UserName` = git commit email, `Password` = GitHub PAT; overrides `GIT_USER_EMAIL`/`GITHUB_TOKEN`. If omitted, the GitHub PAT already configured as appsec-scout's GitHub tracker credential is reused automatically (fetched from the running `app` container); `docker/claude/.env`'s `GITHUB_TOKEN` is only a last-resort fallback.
- `-Rebuild` — force a clean `--no-cache` rebuild and re-export host CA certs first. Not required for routine use.

Proxy/TLS settings (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, `SSL_CERT_FILE`) are read from the repo root `.env`, layered under `docker/claude/.env` — set those once at the root, not per container.

```powershell
.\scripts\invoke-claude.ps1 -Mode login
.\scripts\invoke-claude.ps1
.\scripts\invoke-claude.ps1 -Mode task -Task "Add input validation to the SecurityEvent edit form"
.\scripts\invoke-claude.ps1 -Mode task -Task "..." -Credential (Get-Credential) -Name "Your Name"
```

## invoke-ops.ps1

Opens the `ops` sandboxed container for appsec investigation (code analysis, secret scanning, dependency auditing, history cleaning), or runs an org-wide SBOM/vulnerability/secret scan across every Azure DevOps repository. Every run rebuilds the `ops` image first (respecting Docker's layer cache, so this is fast when nothing changed) — you never need `-Rebuild` just to pick up a Dockerfile/entrypoint/collect-sboms.sh change.

**Parameters**
- `-Mode <shell|login|sbom-scan>` — default `shell`.
  - `sbom-scan` clones and Trivy-scans every non-disabled repo in the target AzDO organization. Generated reports are imported into appsec-scout as `Attachment`s incrementally as each repo finishes (a scheduled `sbom:import-pending-scans` tick picks up new results every minute), not just once the whole scan completes — unless `-SkipUpload`. Requires the core stack (`appsec-scout.ps1`) to already be running: every scan runs against the shared `trivy-server` container rather than downloading its own vulnerability database, and fails fast with a clear message if that shared token isn't present.
- `-Repo` / `-Branch` / `-Name` / `-Credential` — same meaning as in `invoke-claude.ps1`, for cloning a GitHub repo into the ops shell. `-Credential` follows the same vault-first fallback as `invoke-claude.ps1`.
- `-Organization <string>` — AzDO organization to scan; overrides `AZDO_ORG`.
- `-AzdoCredential <PSCredential>` — `Password` = AzDO PAT with "Code (Read)" scope; overrides `AZDO_PAT`. `UserName` is unused. If omitted (in `-Mode sbom-scan`), the PAT and organization already configured as appsec-scout's AzDO Advanced Security source credential are reused automatically (fetched from the running `app` container); `docker/ops/.env`'s `AZDO_PAT`/`AZDO_ORG` are only a last-resort fallback.
- `-ProjectFilter <regex>` / `-RepositoryFilter <regex>` — restrict the scan by project/repo name; override `AZDO_PROJECT_FILTER`/`AZDO_REPO_FILTER`.
- `-OutputDir <path>` — host directory for scan output; overrides `SBOM_OUTPUT_DIR`.
- `-SkipUpload` — leave generated reports on disk without uploading them as attachments, including via the scheduled per-minute import (the scan run is marked so `sbom:import-pending-scans` skips it too).
- `-Rebuild` — force a clean `--no-cache` rebuild and re-export host CA certs first. Not required for routine use.

Proxy/TLS settings (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, `SSL_CERT_FILE`) are read from the repo root `.env`, layered under `docker/ops/.env` — set those once at the root, not per container.

```powershell
.\scripts\invoke-ops.ps1 -Mode login
.\scripts\invoke-ops.ps1
.\scripts\invoke-ops.ps1 -Mode sbom-scan -AzdoCredential (Get-Credential)
.\scripts\invoke-ops.ps1 -Mode sbom-scan -AzdoCredential (Get-Secret AzureDevOps) -ProjectFilter '^Portal$'
```

Before running a full scan, validate the PAT with `test-AzureDevOpsToken.ps1` below — it fails in seconds instead of after cloning every repo in the organization.

## test-GitHubToken.ps1

Validates a GitHub PAT against `https://api.github.com/user` and prints the authenticated login and OAuth scopes.

**Parameters**
- `-Credential <PSCredential>` — `Password` = GitHub PAT. `UserName` is unused.

```powershell
.\scripts\test-GitHubToken.ps1 -Credential (Get-Credential -UserName 'PAT' -Message 'Enter your GitHub PAT')
.\scripts\test-GitHubToken.ps1 -Credential (Get-Secret -Name 'GitHub PAT')
```

## test-AzureDevOpsToken.ps1

Validates an Azure DevOps PAT with a single lightweight call (`GET _apis/projects?$top=1`) and reports the HTTP status and project count, then resolves and prints the real account (display name + email) the PAT belongs to via the profile API. Use this to rule out credential problems before running a full `invoke-ops.ps1 -Mode sbom-scan`.

**Parameters**
- `-Credential <PSCredential>` — `Password` = AzDO PAT. `UserName` is not used for authentication — it's just a free-text label typed into `Get-Credential`, not the PAT's real owner, so the script looks up the actual identity from Azure DevOps instead.
- `-Organization <string>` — AzDO organization name; default `EESC-CoR`.

```powershell
.\scripts\test-AzureDevOpsToken.ps1 -Credential (Get-Credential -UserName 'PAT' -Message 'Enter your Azure DevOps PAT')
.\scripts\test-AzureDevOpsToken.ps1 -Credential (Get-Secret -Name 'AzureDevOps') -Organization 'EESC-CoR'
```

## validate-workflows.cjs

Node script that lints every `.yml`/`.yaml` file under `.github/workflows` with `actionlint` (via `npx`). Exits non-zero if any file fails validation.

```powershell
node .\scripts\validate-workflows.cjs
```
