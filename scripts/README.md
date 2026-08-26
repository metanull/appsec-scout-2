# scripts/

PowerShell entry points for developing, running, and operating AppSec Scout. All scripts are meant to be run from the repository root (they resolve their own root via `$MyInvocation`).

| Script | Purpose |
|--------|---------|
| [appsec-scout.ps1](#appsec-scoutps1) | Start/rebuild the application stack |
| [invoke-check.ps1](#invoke-checkps1) | Run CI checks (lint, tests, static analysis, dependencies) |
| [invoke-fix.ps1](#invoke-fixps1) | Run mutating auto-fixes (lint, dependency updates) |
| [invoke-ops.ps1](#invoke-opsps1) | Open an appsec-ops shell, run Claude Code sandboxed, or run an org-wide SBOM/vuln/secret scan or static analysis scan |
| [test-GitHubToken.ps1](#test-githubtokenps1) | Validate a GitHub PAT |
| [test-AzureDevOpsToken.ps1](#test-azuredevopstokenps1) | Validate an Azure DevOps PAT |
| [validate-workflows.cjs](#validate-workflowscjs) | Lint GitHub Actions workflow YAML |
| [github-packages.cjs](#github-packagescjs) | List owned GitHub repos with their packages, visibility, and storage size |

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
- `-Check <all|lint|test|test-sqlite|test-mysql|test-pgsql|static-analysis|smoke|dependencies|npm-audit>` — default `all`. `test` runs `test-sqlite`, `test-mysql`, and `test-pgsql`.

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

## invoke-ops.ps1

Opens the `ops` sandboxed container — appsec investigation (code analysis, secret scanning, dependency auditing, history cleaning), Claude Code (interactive or an autonomous task that clones a repo, does the work, and opens a PR), an org-wide SBOM/vulnerability/secret scan, or an org-wide static analysis scan across every Azure DevOps repository. One container image throughout — Claude Code, PHP/Composer, .NET SDK, Java/Maven/Gradle, Trivy, BFG, and the AzDO/GitHub CLIs are all present regardless of which mode you run. Every invocation rebuilds the `ops` image first (respecting Docker's layer cache, so this is fast when nothing changed) — you never need `-Rebuild` just to pick up a Dockerfile/entrypoint/collect-sboms.sh/collect-static-analysis.sh change.

Exactly one of `-Shell` (default — doesn't need to be typed), `-Claude`, `-SbomScan`, or `-StaticAnalysis` is active per invocation; PowerShell rejects any parameter combination that crosses between them.

**`-Shell`** (default) — interactive bash shell.
- `-Repo <url>` / `-Branch <name>` — repo to clone into the shell; override `REPO_URL`/`REPO_BRANCH`.
- `-Name <string>` — git commit display name; overrides `GIT_USER_NAME`.
- `-Credential <PSCredential>` — `UserName` = git commit email, `Password` = GitHub PAT; overrides `GIT_USER_EMAIL`/`GITHUB_TOKEN`. If omitted, the GitHub PAT already configured as appsec-scout's GitHub tracker credential is reused automatically (fetched from the running `app` container); `docker/ops/.env`'s `GITHUB_TOKEN` is only a last-resort fallback.

**`-Claude`** — runs Claude Code inside the same sandboxed container.
- Bare `-Claude` opens an interactive session (clones `-Repo` first if set).
- `-Login` — one-time OAuth login, saved to the `claude_credentials` volume shared by every mode. Combined with `-Task`, login runs first and the task only runs if it succeeds (a failed login aborts the whole invocation); combined with nothing else, it stops once the login flow completes.
- `-Task <string>` — autonomous run: clone `-Repo`, execute this prompt non-interactively, push a branch, open a PR.
- `-Repo` / `-Branch` / `-Name` / `-Credential` — same meaning as under `-Shell`.

**`-SbomScan`** — clones and Trivy-scans every non-disabled repo in the target AzDO organization. Generated reports are imported into appsec-scout as `Attachment`s incrementally as each repo finishes (a scheduled `sbom:import-pending-scans` tick picks up new results every minute), not just once the whole scan completes — unless `-SkipUpload`. Requires the core stack (`appsec-scout.ps1`) to already be running: every scan runs against the shared `trivy-server` container rather than downloading its own vulnerability database, and fails fast with a clear message if that shared token isn't present.
- `-Organization <string>` — AzDO organization to scan; overrides `AZDO_ORG`.
- `-Credential <PSCredential>` — `Password` = AzDO PAT with "Code (Read)" scope; overrides `AZDO_PAT`. `UserName` is unused. If omitted, the PAT and organization already configured as appsec-scout's AzDO Advanced Security source credential are reused automatically (fetched from the running `app` container); `docker/ops/.env`'s `AZDO_PAT`/`AZDO_ORG` are only a last-resort fallback.
- `-ProjectFilter <regex>` / `-RepositoryFilter <regex>` — restrict the scan by project/repo name; override `AZDO_PROJECT_FILTER`/`AZDO_REPO_FILTER`.
- `-OutputDir <path>` — host directory for scan output; overrides `SBOM_OUTPUT_DIR`.
- `-SkipUpload` — leave generated reports on disk without uploading them as attachments, including via the scheduled per-minute import (the scan run is marked so `sbom:import-pending-scans` skips it too).
- `-Resume` — skip every repository already recorded in any previous run's `run.jsonl` under the output directory and continue from there, instead of rescanning everything; repeated interrupt/resume cycles keep accumulating. Fails clearly if no prior run is found.

**`-StaticAnalysis`** — clones and statically analyzes every non-disabled repo in the target AzDO organization: Roslynator for .NET (`*.sln`, restored/built first), SpotBugs + Find Security Bugs for Java (built with the repo's own `mvnw`/`gradlew` if present, else the image's Maven/Gradle). Generated SARIF reports are imported into appsec-scout as `Attachment`s incrementally as each repo finishes (a scheduled `staticanalysis:import-pending-scans` tick picks up new results every minute), not just once the whole scan completes — unless `-SkipUpload`. Requires the core stack (`appsec-scout.ps1`) to already be running. Shares `-Organization`/`-ProjectFilter`/`-RepositoryFilter`/`-OutputDir`/`-SkipUpload`/`-Resume`/`-Credential` with `-SbomScan` (same meaning as above; `-OutputDir` overrides `STATIC_ANALYSIS_OUTPUT_DIR` instead of `SBOM_OUTPUT_DIR`).

**`-Rebuild`** — combinable with any of the above; forces a clean `--no-cache` rebuild and re-exports host CA certs first. Not required for routine use.

Proxy/TLS settings (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, `SSL_CERT_FILE`) are read from the repo root `.env`, layered under `docker/ops/.env` — set those once at the root, not per container.

```powershell
.\scripts\invoke-ops.ps1
.\scripts\invoke-ops.ps1 -Claude -Login
.\scripts\invoke-ops.ps1 -Claude -Task "Add input validation to the SecurityEvent edit form"
.\scripts\invoke-ops.ps1 -Claude -Login -Task "..." -Credential (Get-Credential) -Name "Your Name"
.\scripts\invoke-ops.ps1 -SbomScan -Credential (Get-Credential)
.\scripts\invoke-ops.ps1 -SbomScan -Credential (Get-Secret AzureDevOps) -ProjectFilter '^Portal$'
.\scripts\invoke-ops.ps1 -SbomScan -Resume -Credential (Get-Credential)
.\scripts\invoke-ops.ps1 -StaticAnalysis -Credential (Get-Credential)
.\scripts\invoke-ops.ps1 -StaticAnalysis -Resume -Credential (Get-Credential)
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

Validates an Azure DevOps PAT with a single lightweight call (`GET _apis/projects?$top=1`) and reports the HTTP status and project count, then resolves and prints the real account (display name + email) the PAT belongs to via the profile API. Use this to rule out credential problems before running a full `invoke-ops.ps1 -SbomScan`.

**Parameters**
- `-Credential <PSCredential>` — `Password` = AzDO PAT. `UserName` is not used for authentication — it's just a free-text label typed into `Get-Credential`, not the PAT's real owner, so the script looks up the actual identity from Azure DevOps instead.
- `-Organization <string>` — AzDO organization name; default `EESC-CoR`.

```powershell
.\scripts\test-AzureDevOpsToken.ps1 -Credential (Get-Credential -UserName 'PAT' -Message 'Enter your Azure DevOps PAT')
.\scripts\test-AzureDevOpsToken.ps1 -Credential (Get-Secret -Name 'AzureDevOps') -Organization 'EESC-CoR'
```

## github-packages.cjs

Node script (admin terminal tool, not wired into the web UI) that enumerates the GitHub repositories owned by the authenticated account and, for each one, the packages published from it: package type, `public`/`private` visibility, storage size, and per-repository totals (public, private, cumulated), plus a grand total. Repositories without packages, and packages whose repository isn't an owned one, are listed separately.

The GitHub packages API reports no size at all, so sizes are read from the registries themselves:

- **`container`** — every distinct blob (config + layers) from the GHCR manifests, following multi-arch index entries. Blobs are deduplicated by digest, within a version and across the whole package, so the package total is the storage actually occupied; `--versions` also prints the naive sum of all versions for comparison.
- **`npm`** — the `Content-Length` of each version's tarball on `npm.pkg.github.com` (the registry rejects `HEAD`, so the script issues a `GET` and drops the body without downloading it). npm versions share nothing, so the package total is their sum.
- **`maven`, `nuget`, `rubygems`** — no size source; these show `n/a` and are counted separately in the totals instead of silently as zero.

Only **private** packages count against the GitHub Packages storage quota, so the totals keep public and private apart.

The GitHub PAT is read from the appsec-scout credential vault (`github-repos.token`, the GitHub Repos source control credential) via the running `app` container, so it doesn't have to be re-entered. The PAT needs `read:packages`, plus `repo` to see private repositories and packages; a missing `read:packages` scope is reported per package type instead of failing the whole run.

**Parameters**
- `--repo <name>` — restrict the report to a single owned repository.
- `--versions` — list every version of every package (digest/version, date, size, container tags) instead of package totals only.
- `--token <pat>` — use this PAT instead of the vault (the `GITHUB_TOKEN` environment variable is honoured too). Useful when the stack isn't running.
- `--json` — emit the full result as JSON instead of the text report.
- `--no-sizes` — skip the registry calls; much faster, every size shows `n/a`.

```powershell
node .\scripts\github-packages.cjs
```

```powershell
node .\scripts\github-packages.cjs --repo inventory-app --versions
```

## validate-workflows.cjs

Node script that lints every `.yml`/`.yaml` file under `.github/workflows` with `actionlint` (via `npx`). Exits non-zero if any file fails validation.

```powershell
node .\scripts\validate-workflows.cjs
```
