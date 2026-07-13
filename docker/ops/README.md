# docker/ops/

A sandboxed, ephemeral container for hands-on appsec investigation against any repository — code analysis, secret scanning, dependency auditing, SBOM generation, Git history cleaning, and running Claude Code itself, interactively or as an autonomous task. It has no access to the host filesystem beyond what is explicitly bind-mounted, and is driven exclusively via [scripts/invoke-ops.ps1](../../scripts/README.md#invoke-opsps1) — never `docker compose` directly.

`-SbomScan` and `-StaticAnalysis` both depend on the core stack (`appsec-scout.ps1`) already being up: they reuse the AzDO PAT already configured in appsec-scout's credential vault. `-SbomScan` additionally runs every Trivy scan against the shared `trivy-server` container rather than downloading its own vulnerability database. Neither is meant to run standalone — start `appsec-scout.ps1` first.

## What's inside

- **git**, **gh** (GitHub CLI), **jq**, **curl** — repo/API access
- **PHP 8.4 CLI** + Composer, with Pint, PHPStan, and Pest installed globally — audit any PHP repo without a project-specific vendor/ install
- **.NET 10 SDK** + Roslynator CLI — restore/build/analyze .NET solutions
- **Eclipse Temurin JDK (current LTS)** + **Maven** + **Gradle** + **SpotBugs** with the **Find Security Bugs** plugin (checksum-verified at build time) — build and statically analyze any Java repo (its own `mvnw`/`gradlew` wrapper is preferred when present); also the JVM `bfg` runs on
- **Trivy** — SBOM (CycloneDX), vulnerability, and secret scanning
- **BFG Repo-Cleaner** (checksum- and signature-verified at build time) — strip secrets/large blobs from Git history
- **Claude Code** — run via `-Claude`, once authenticated via `-Claude -Login`; the plain shell never launches it automatically

One image throughout — every mode (`-Shell`, `-Claude`, `-SbomScan`, `-StaticAnalysis`) runs in the same container with the full toolset above available, since Claude is mostly used to work on code and may need any of it. The image runs as a non-root `ops` user (falls back to root only for system package installs during build).

## Usage

All usage goes through `invoke-ops.ps1`; see [scripts/README.md](../../scripts/README.md#invoke-opsps1) for the full parameter reference. Summary of modes:

```powershell
# Interactive shell (clones docker/ops/.env's REPO_URL first, if set)
.\scripts\invoke-ops.ps1

# One-time Claude OAuth login (persisted to the claude_credentials volume)
.\scripts\invoke-ops.ps1 -Claude -Login

# Interactive Claude Code session in the sandboxed container
.\scripts\invoke-ops.ps1 -Claude

# Autonomous Claude task: clone a repo, do the work, push a branch, open a PR
.\scripts\invoke-ops.ps1 -Claude -Task "Add input validation to the SecurityEvent edit form"

# Org-wide SBOM + vulnerability + secret scan across every Azure DevOps repo,
# with results uploaded into appsec-scout as Attachments
.\scripts\invoke-ops.ps1 -SbomScan -Credential (Get-Credential)

# Org-wide static analysis (Roslynator for .NET, SpotBugs + Find Security Bugs for Java)
# across every Azure DevOps repo, with results uploaded into appsec-scout as Attachments
.\scripts\invoke-ops.ps1 -StaticAnalysis -Credential (Get-Credential)
```

Every `invoke-ops.ps1` run already rebuilds the image (respecting Docker's layer cache, so it's fast when nothing changed) — a plain `.\scripts\invoke-ops.ps1` picks up changes to this Dockerfile, `entrypoint.sh`, `collect-sboms.sh`, or `collect-static-analysis.sh` automatically. Use `-Rebuild` only when you want a clean `--no-cache` build or a fresh CA cert export:

```powershell
.\scripts\invoke-ops.ps1 -Rebuild
```

## Configuration

Copy `.env.example` to `.env` in this folder and fill in defaults, or pass everything per-run as `invoke-ops.ps1` parameters (preferred for secrets — nothing touches disk). `.env` is loaded exclusively by `invoke-ops.ps1` and does not affect the `app`, `mysql`, or `redis` containers.

| Variable | Purpose |
|----------|---------|
| `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` / `SSL_CERT_FILE` | Corporate proxy / custom TLS. `SSL_CERT_FILE` is populated automatically from the Windows cert store by `-Rebuild`. |
| `REPO_URL` / `REPO_BRANCH` | Repository to clone into `/workspace` on shell start. |
| `GIT_USER_NAME` / `GIT_USER_EMAIL` / `GITHUB_TOKEN` | Git commit identity and GitHub PAT for cloning/pushing. `GITHUB_TOKEN` is only a last-resort fallback — `invoke-ops.ps1` first reuses the GitHub PAT already configured as appsec-scout's GitHub tracker credential. |
| `AZDO_ORG` | Azure DevOps organization for `-SbomScan` / `-StaticAnalysis`. Fallback only — reused from appsec-scout's AzDO source credential by default. |
| `AZDO_PAT` | Azure DevOps PAT with "Code (Read)" scope. Fallback only — `invoke-ops.ps1` first reuses the PAT already configured as appsec-scout's AzDO Advanced Security source credential; never commit a real PAT here. |
| `AZDO_PROJECT_FILTER` / `AZDO_REPO_FILTER` | Optional regex filters on project/repository name, shared by `-SbomScan` and `-StaticAnalysis`. |
| `SBOM_OUTPUT_DIR` | Host directory that receives `-SbomScan` output (default `./output/sbom-scan`). |
| `STATIC_ANALYSIS_OUTPUT_DIR` | Host directory that receives `-StaticAnalysis` output (default `./output/static-analysis-scan`). |

Before running a full scan, validate the PAT with [scripts/test-AzureDevOpsToken.ps1](../../scripts/README.md#test-azuredevopstokenps1) — it fails in seconds instead of after cloning every repo in the organization.

## SBOM/vulnerability/secret scan (`--sbom-scan`)

`collect-sboms.sh` (invoked via `entrypoint.sh --sbom-scan`, i.e. `-SbomScan`) enumerates every project and non-disabled repository in the target Azure DevOps organization and, for each repo:

1. Shallow-clones it.
2. If it contains any `*.sln`, attempts `dotnet restore` + `dotnet build` (non-fatal — Trivy still runs against unresolved package ranges if this fails).
3. Runs up to three Trivy scans against the shared `trivy-server` container (`--server`/`--token`, read from the `trivy_token` volume — see docker-compose.yml), controlled by `AZDO_SCAN_TYPES` (default `sbom,vuln,secret`):
   - `sbom` — `trivy fs --format cyclonedx` → CycloneDX SBOM
   - `vuln` — `trivy fs --scanners vuln --format sarif` → vulnerability report
   - `secret` — `trivy fs --scanners secret --format sarif` → secret-scan report
4. Deletes the clone immediately after scanning.

Since every scan reuses the core stack's `trivy-server` container instead of downloading its own vulnerability database, `collect-sboms.sh` fails fast with a clear message if the shared token isn't present — i.e. if the core stack hasn't been started.

Results land under `$OUTPUT_DIR/<UTC timestamp>/<project>/<repo>.{cdx,vuln.sarif,secrets.sarif}.json`, plus a `run.jsonl` (one line per repo, appended as each repo finishes) and a `summary.json` (aggregate counts, written once the whole scan completes). Reports are picked up into appsec-scout incrementally, not just at the end: a scheduled `sbom:import-pending-scans` tick in the `app` container reads new `run.jsonl` lines every minute and imports them via the same logic as `assets:import-attachment`, tracking a per-run cursor file so nothing is imported twice. `invoke-ops.ps1` also triggers that command once more right after the scan container exits, to flush anything the last scheduled tick missed — unless `-SkipUpload` was passed, in which case `collect-sboms.sh` marks the run directory so the scheduled tick skips it too.

**Additional environment variables** (set via `docker/ops/.env` or forwarded by `invoke-ops.ps1`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `AZDO_SCAN_TYPES` | `sbom,vuln,secret` | Comma-separated subset of scan types to run. |
| `TRIVY_SERVER_URL` | `http://trivy-server:4954` | Base URL of the shared Trivy server every scan authenticates against. Internal wiring — not normally overridden. |
| `TRIVY_TIMEOUT` | `15m` | Per-scan Trivy timeout (secret scanning large trees needs more than Trivy's 5m default). |
| `AZDO_RESTORE_TIMEOUT` | `600` (seconds) | Timeout for `dotnet restore`. |
| `AZDO_BUILD_TIMEOUT` | `900` (seconds) | Timeout for `dotnet build`. |

**Failure handling**: if the Azure DevOps API call itself fails (invalid/expired PAT, wrong scope, network/proxy issue), the script now fails loudly — it prints the HTTP status and a response snippet, points at `test-AzureDevOpsToken.ps1`, and exits non-zero — rather than silently reporting "0 projects scanned".

## Static analysis scan (`--static-analysis`)

`collect-static-analysis.sh` (invoked via `entrypoint.sh --static-analysis`, i.e. `-StaticAnalysis`) enumerates every project and non-disabled repository in the target Azure DevOps organization and, for each repo:

1. Shallow-clones it.
2. .NET: for every `*.sln` found, attempts `dotnet restore` + `dotnet build`, then (if restore succeeded) `roslynator analyze` on that solution. Results from every solution in the repo are merged into one SARIF file.
3. Java: builds the topmost `pom.xml`/`build.gradle[.kts]` in the tree — the repo's own `mvnw`/`gradlew` wrapper if present, else the image's Maven/Gradle install — then runs SpotBugs (with the Find Security Bugs plugin) against every directory that ends up containing compiled `.class` files.
4. Deletes the clone immediately after analysis.

Controlled by `STATIC_ANALYSIS_TYPES` (default `dotnet,java`). Build failures are non-fatal for either language — the corresponding report is simply not generated for that repo, mirroring how `collect-sboms.sh` already treats `dotnet restore`/`build` failures.

Results land under `$OUTPUT_DIR/<UTC timestamp>/<project>/<repo>.{dotnet,java}.sarif`, plus a `run.jsonl` and `summary.json` with the same shape and semantics as the SBOM scan's (including `-Resume` support). Reports are picked up into appsec-scout incrementally via a scheduled `staticanalysis:import-pending-scans` tick, exactly like `sbom:import-pending-scans` — see the SBOM scan section above for the exact mechanics, which this mirrors.

**Additional environment variables** (set via `docker/ops/.env` or forwarded by `invoke-ops.ps1`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `STATIC_ANALYSIS_TYPES` | `dotnet,java` | Comma-separated subset of analysis types to run. |
| `DOTNET_RESTORE_TIMEOUT` | `600` (seconds) | Timeout for `dotnet restore`. |
| `DOTNET_BUILD_TIMEOUT` | `900` (seconds) | Timeout for `dotnet build`. |
| `JAVA_BUILD_TIMEOUT` | `900` (seconds) | Timeout for the Maven/Gradle build. |
| `ANALYSIS_TIMEOUT` | `900` (seconds) | Timeout for each Roslynator/SpotBugs invocation. |
