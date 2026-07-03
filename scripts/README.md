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

Starts the app via Docker Compose: builds the image, brings up containers, waits for `/up` to respond, runs migrations/seeders, and opens the app in the browser.

**Parameters**
- `-Rebuild` — stop/remove containers and volumes, rebuild the `app` image, then start fresh (also re-imports `.credentials.json` if present).
- `-Force` — with `-Rebuild`, rebuilds with `--no-cache`.

```powershell
.\scripts\appsec-scout.ps1
.\scripts\appsec-scout.ps1 -Rebuild
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

Runs Claude Code in an isolated, ephemeral container with no access to the host filesystem — either interactively, for one-time login, or as an autonomous task that clones a repo, does the work, and opens a PR.

**Parameters**
- `-Mode <shell|login|task>` — default `shell`.
- `-Task <string>` — task prompt; required with `-Mode task`.
- `-Repo <url>` — repo to clone; overrides `CLAUDE_REPO_URL`.
- `-Branch <name>` — branch to clone/PR against; overrides `CLAUDE_REPO_BRANCH`.
- `-Name <string>` — git commit display name; overrides `GIT_USER_NAME`.
- `-Credential <PSCredential>` — `UserName` = git commit email, `Password` = GitHub PAT; overrides `GIT_USER_EMAIL`/`GITHUB_TOKEN`.
- `-Rebuild` — export host CA certs and rebuild the `claude` image first.

```powershell
.\scripts\invoke-claude.ps1 -Mode login
.\scripts\invoke-claude.ps1
.\scripts\invoke-claude.ps1 -Mode task -Task "Add input validation to the SecurityEvent edit form"
.\scripts\invoke-claude.ps1 -Mode task -Task "..." -Credential (Get-Credential) -Name "Your Name"
```

## invoke-ops.ps1

Opens the `ops` sandboxed container for appsec investigation (code analysis, secret scanning, dependency auditing, history cleaning), or runs an org-wide SBOM/vulnerability/secret scan across every Azure DevOps repository.

**Parameters**
- `-Mode <shell|login|sbom-scan>` — default `shell`.
  - `sbom-scan` clones and Trivy-scans every non-disabled repo in the target AzDO organization, then uploads each generated report into appsec-scout as an `Attachment` (unless `-SkipUpload`).
- `-Repo` / `-Branch` / `-Name` / `-Credential` — same meaning as in `invoke-claude.ps1`, for cloning a GitHub repo into the ops shell.
- `-Organization <string>` — AzDO organization to scan; overrides `AZDO_ORG`.
- `-AzdoCredential <PSCredential>` — `Password` = AzDO PAT with "Code (Read)" scope; overrides `AZDO_PAT`. `UserName` is unused.
- `-ProjectFilter <regex>` / `-RepositoryFilter <regex>` — restrict the scan by project/repo name; override `AZDO_PROJECT_FILTER`/`AZDO_REPO_FILTER`.
- `-OutputDir <path>` — host directory for scan output; overrides `SBOM_OUTPUT_DIR`.
- `-SkipUpload` — leave generated reports on disk without uploading them as attachments.
- `-Rebuild` — export host CA certs and rebuild the `ops` image first.

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

Validates an Azure DevOps PAT with a single lightweight call (`GET _apis/projects?$top=1`) and reports the HTTP status and project count. Use this to rule out credential problems before running a full `invoke-ops.ps1 -Mode sbom-scan`.

**Parameters**
- `-Credential <PSCredential>` — `Password` = AzDO PAT. `UserName` is unused.
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
