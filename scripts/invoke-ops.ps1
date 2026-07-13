<#
.SYNOPSIS
    Opens an appsec-ops shell in a sandboxed Docker container, or runs Claude Code inside it.
.DESCRIPTION
    Launches the `ops` container for hands-on appsec investigation: code analysis, repository
    archaeology, secret scanning, dependency auditing, history cleaning, org-wide SBOM scanning,
    org-wide static analysis, and running Claude Code itself — interactively or as an autonomous
    task. Same sandboxed, ephemeral, no-host-filesystem-access container throughout; -Claude just
    changes what the entrypoint execs once inside it.
.PARAMETER Shell
    Interactive bash shell (default — this switch does not need to be typed). Clones -Repo first
    if set. 'claude' is available inside if already authenticated, but is never auto-launched.
.PARAMETER Claude
    Runs Claude Code inside the same sandboxed container. Bare -Claude opens an interactive
    session (clones -Repo first if set). Combine with -Login and/or -Task — see those parameters.
.PARAMETER Login
    One-time Claude OAuth login (with -Claude); saves credentials to the 'claude_credentials'
    volume, shared by every mode in this script. Runs first if combined with -Task: login happens,
    then (only if it succeeds) the task runs. A failed login aborts the whole invocation. Combined
    with nothing else, it stops once the login flow completes — same as before.
.PARAMETER Task
    Autonomous run (with -Claude): clone -Repo, execute this prompt non-interactively, push a
    branch, open a PR. Runs after -Login when both are given.
.PARAMETER SbomScan
    Collects SBOMs (CycloneDX, via Trivy) from every repository in an Azure DevOps organization,
    restoring/building any *.sln first for precise .NET results. Runs to completion in a single
    container invocation; each repo is deleted immediately after it is scanned. Output lands on
    the host under SBOM_OUTPUT_DIR (default .\output\sbom-scan\<timestamp>\). Reports are
    uploaded into appsec-scout as Attachments on the matching SoftwareSystem/SecurityContainer
    incrementally, as soon as each repository finishes — a scheduled `sbom:import-pending-scans`
    tick in the `app` container picks up every new run.jsonl line every minute, so you don't have
    to wait for the whole (possibly multi-hour) scan to see results land. This script also
    triggers that same command once more right after the scan container exits, to flush anything
    the last scheduled tick hasn't picked up yet. Pass -SkipUpload to opt this run out of both
    entirely (see -SkipUpload).
.PARAMETER StaticAnalysis
    Runs static analysis (Roslynator for .NET, SpotBugs + Find Security Bugs for Java) against
    every repository in an Azure DevOps organization, restoring/building each repo first (its own
    mvnw/gradlew wrapper if present, else the image's Maven/Gradle) so the analyzers see resolved
    references instead of just source text. Runs to completion in a single container invocation;
    each repo is deleted immediately after it is analyzed. Output lands on the host under
    STATIC_ANALYSIS_OUTPUT_DIR (default .\output\static-analysis-scan\<timestamp>\). Reports are
    uploaded into appsec-scout as Attachments incrementally, the same way -SbomScan's are — a
    scheduled `staticanalysis:import-pending-scans` tick picks up every new run.jsonl line every
    minute, and this script triggers that command once more right after the scan container exits.
    Pass -SkipUpload to opt this run out of both entirely (see -SkipUpload). Shares -Organization/
    -ProjectFilter/-RepositoryFilter/-OutputDir/-Resume/-SkipUpload/-Credential with -SbomScan.
.PARAMETER Repo
    GitHub HTTPS URL to clone (-Shell / -Claude). Overrides REPO_URL from .env.
.PARAMETER Branch
    Branch to clone (-Shell / -Claude). Overrides REPO_BRANCH from .env. With -Claude -Task, also
    the PR base branch.
.PARAMETER Name
    Git display name used in commits (-Shell / -Claude), e.g. "Pascal HAVELANGE". Overrides
    GIT_USER_NAME from .env.
.PARAMETER Credential
    GitHub credential for cloning/pushing (-Shell / -Claude), or Azure DevOps credential
    (-SbomScan / -StaticAnalysis) — which one depends on which of those switches is passed;
    there's no ambiguity since only one is ever active per invocation.
    -Shell / -Claude:               UserName = git commit email — overrides GIT_USER_EMAIL.
                                     Password = GitHub PAT      — overrides GITHUB_TOKEN.
    -SbomScan / -StaticAnalysis:    Password = AzDO PAT with "Code (Read)" scope across the
                                     organization — overrides AZDO_PAT from .env. UserName
                                     is not used.
    If omitted, the matching PAT already configured as appsec-scout's GitHub tracker or AzDO
    Advanced Security source credential is fetched from the running `app` container and reused;
    if that isn't available either, the container falls back to GITHUB_TOKEN/AZDO_PAT from
    docker/ops/.env.
    Tip: pass (Get-Credential) for an interactive prompt, or retrieve a stored entry from Windows
    Credential Manager with Get-StoredCredential (module CredentialManager).
.PARAMETER Organization
    Azure DevOps organization to scan (-SbomScan / -StaticAnalysis). Overrides AZDO_ORG from .env.
.PARAMETER ProjectFilter
    Regex applied to project names (-SbomScan / -StaticAnalysis). Overrides AZDO_PROJECT_FILTER
    from .env.
.PARAMETER RepositoryFilter
    Regex applied to repository names (-SbomScan / -StaticAnalysis). Overrides AZDO_REPO_FILTER
    from .env.
.PARAMETER OutputDir
    Host directory to receive scan output (-SbomScan / -StaticAnalysis). Overrides
    SBOM_OUTPUT_DIR (-SbomScan) or STATIC_ANALYSIS_OUTPUT_DIR (-StaticAnalysis) from .env.
.PARAMETER SkipUpload
    Skip uploading generated reports into appsec-scout as attachments (-SbomScan /
    -StaticAnalysis). Files still land under OutputDir either way. This also prevents the
    per-minute scheduled import (sbom:import-pending-scans or staticanalysis:import-pending-scans)
    from picking up this run, since that runs independently of this script — the scan script
    drops a marker in the run's output directory that the scheduled command checks for and skips.
.PARAMETER Resume
    Resume a previous scan (-SbomScan / -StaticAnalysis) instead of starting from scratch. Walks backward
    from the most recent run under OutputDir, collecting the unbroken chain of interrupted or
    zero-progress attempts, and skips every repository already recorded in any of them —
    which only leaves out whichever repository was still being scanned when the chain was last
    interrupted (e.g. a container/engine crash) plus anything not yet reached, so repeated
    interrupt/resume cycles keep accumulating instead of losing earlier progress each time. The
    walk stops at — and never touches — the first run that genuinely completed with real
    progress, so an old, unrelated, already-finished scan sitting in the same output directory
    is never silently treated as "already scanned." Results land in a new run directory as
    usual; nothing is overwritten. Fails with a clear error if no prior run.jsonl is found, or
    if the most recent run already completed and there is nothing left to resume.
.PARAMETER Rebuild
    Forces a clean --no-cache rebuild of the ops image and re-exports host CA certificates.
    Not required to pick up ordinary code changes — every run already rebuilds the image
    (respecting Docker's layer cache), so a stale image is never used just because -Rebuild
    was omitted. Combinable with -Shell, -Claude, -SbomScan, or -StaticAnalysis.
.EXAMPLE
    .\invoke-ops.ps1
.EXAMPLE
    .\invoke-ops.ps1 -Repo https://github.com/org/repo.git -Branch main
.EXAMPLE
    .\invoke-ops.ps1 -Repo https://github.com/org/repo.git -Credential (Get-Credential) -Name "Pascal HAVELANGE"
.EXAMPLE
    .\invoke-ops.ps1 -Claude -Login
.EXAMPLE
    .\invoke-ops.ps1 -Claude
.EXAMPLE
    .\invoke-ops.ps1 -Claude -Task "Add input validation to the SecurityEvent edit form"
.EXAMPLE
    .\invoke-ops.ps1 -Claude -Login -Task "..." -Repo https://github.com/org/repo.git -Branch feature/x
.EXAMPLE
    .\invoke-ops.ps1 -SbomScan -Credential (Get-Credential)
.EXAMPLE
    .\invoke-ops.ps1 -SbomScan -Credential (Get-Credential) -ProjectFilter '^Portal$'
.EXAMPLE
    .\invoke-ops.ps1 -SbomScan -Resume -Credential (Get-Credential)
.EXAMPLE
    .\invoke-ops.ps1 -StaticAnalysis -Credential (Get-Credential)
.EXAMPLE
    .\invoke-ops.ps1 -StaticAnalysis -Resume -Credential (Get-Credential)
#>
[CmdletBinding(DefaultParameterSetName = 'Shell')]
param(
    [Parameter(ParameterSetName = 'Shell')]
    [Switch]$Shell,

    [Parameter(ParameterSetName = 'Claude')]
    [Switch]$Claude,

    [Parameter(ParameterSetName = 'Claude')]
    [Switch]$Login,

    [Parameter(ParameterSetName = 'Claude')]
    [string]$Task = '',

    [Parameter(ParameterSetName = 'SbomScan')]
    [Switch]$SbomScan,

    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [Switch]$StaticAnalysis,

    [Parameter(ParameterSetName = 'Shell')]
    [Parameter(ParameterSetName = 'Claude')]
    [string]$Repo = '',

    [Parameter(ParameterSetName = 'Shell')]
    [Parameter(ParameterSetName = 'Claude')]
    [string]$Branch = '',

    [Parameter(ParameterSetName = 'Shell')]
    [Parameter(ParameterSetName = 'Claude')]
    [string]$Name = '',

    [Parameter(ParameterSetName = 'Shell')]
    [Parameter(ParameterSetName = 'Claude')]
    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential,

    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [string]$Organization = '',

    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [string]$ProjectFilter = '',

    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [string]$RepositoryFilter = '',

    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [string]$OutputDir = '',

    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [Switch]$SkipUpload,

    [Parameter(ParameterSetName = 'SbomScan')]
    [Parameter(ParameterSetName = 'StaticAnalysis')]
    [Switch]$Resume,

    [Switch]$Rebuild
)

$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectRoot  = Split-Path $MyScriptRoot
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

Import-Module (Join-Path $MyScriptRoot 'lib/Certificates.psm1') -Force

function Invoke-Docker {
    docker @args
    if ($LASTEXITCODE -ne 0) {
        throw ("`docker $($args -join ' ')` failed with exit code $LASTEXITCODE")
    }
}

function Get-SystemVaultCredential {
    <#
    Fetches a single system credential already configured in appsec-scout (e.g. the AzDO
    source's PAT or the GitHub tracker's PAT) via the running `app` container, so the same
    token doesn't have to be re-entered into this container's own .env file. Silently returns
    $null (never throws) if `app` isn't running or the credential isn't configured — callers
    fall back to their own env var/parameter in that case.
    #>
    param(
        [Parameter(Mandatory)][string]$Key,
        [Parameter(Mandatory)][string[]]$EnvFileArgs
    )
    $value = docker compose @EnvFileArgs exec -T app php artisan credentials:system:get $Key 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($value)) {
        return $null
    }
    return ($value -join "`n").Trim()
}

function Invoke-SbomUpload {
    param(
        [Parameter(Mandatory)][string[]]$EnvFileArgs
    )

    # `exec` reads the container's mount as it was when the container was (re)created, so make
    # sure `app` is up to date with the current SBOM_OUTPUT_DIR bind mount before importing.
    docker compose @EnvFileArgs up -d app | Out-Null

    # Delegates to the same idempotent, cursor-tracked command the scheduler already runs
    # every minute (see `sbom:import-pending-scans` in routes/console.php) — this just
    # flushes whatever the scheduler hasn't picked up yet, so nothing is ever imported twice.
    docker compose @EnvFileArgs exec -T app php artisan sbom:import-pending-scans
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "sbom:import-pending-scans could not complete — appsec-scout's database or queue is likely unreachable. Your scan data is untouched on disk under the SBOM output directory; nothing has been lost, and the scheduled import will retry automatically (every minute) once appsec-scout is healthy again."
    }
}

function Invoke-StaticAnalysisUpload {
    param(
        [Parameter(Mandatory)][string[]]$EnvFileArgs
    )

    # `exec` reads the container's mount as it was when the container was (re)created, so make
    # sure `app` is up to date with the current STATIC_ANALYSIS_OUTPUT_DIR bind mount first.
    docker compose @EnvFileArgs up -d app | Out-Null

    # Delegates to the same idempotent, cursor-tracked command the scheduler already runs
    # every minute (see `staticanalysis:import-pending-scans` in routes/console.php) — this
    # just flushes whatever the scheduler hasn't picked up yet, so nothing is imported twice.
    docker compose @EnvFileArgs exec -T app php artisan staticanalysis:import-pending-scans
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "staticanalysis:import-pending-scans could not complete — appsec-scout's database or queue is likely unreachable. Your scan data is untouched on disk under the static analysis output directory; nothing has been lost, and the scheduled import will retry automatically (every minute) once appsec-scout is healthy again."
    }
}

function Resolve-ResumeRunNames {
    <#
    Walks backward from the most recent run under $OutputRoot, collecting the unbroken chain
    of attempts that belong to the same interrupted scan: runs with no summary.json (never
    finished) and completed-but-zero-progress runs (e.g. an earlier resume that itself
    skipped everything). Stops at — and excludes — the first run that genuinely completed
    with real progress, so an old, unrelated, already-finished scan sitting in the same
    output directory is never silently treated as "already scanned" by today's resume.
    Shared by -SbomScan -Resume and -StaticAnalysis -Resume — both scripts write a
    `repositoriesConsidered` field to summary.json using the same meaning.
    #>
    param(
        [Parameter(Mandatory)][string]$OutputRoot
    )

    $allRunDirs = @(Get-ChildItem -Path $OutputRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName 'run.jsonl') } |
        Sort-Object Name -Descending)
    if ($allRunDirs.Count -eq 0) {
        throw "-Resume specified but no previous run.jsonl was found under $OutputRoot"
    }

    $resumeRunDirs = New-Object System.Collections.Generic.List[object]
    $reachedRealBoundary = $false
    foreach ($dir in $allRunDirs) {
        $consideredCount = 0
        $summaryPath = Join-Path $dir.FullName 'summary.json'
        if (Test-Path $summaryPath) {
            $consideredCount = (Get-Content $summaryPath -Raw | ConvertFrom-Json).repositoriesConsidered
        }
        if ($consideredCount -gt 0) {
            $reachedRealBoundary = $true
            break
        }
        $resumeRunDirs.Add($dir)
    }
    if ($resumeRunDirs.Count -eq 0) {
        if ($reachedRealBoundary) {
            throw "-Resume specified but the most recent run ($($allRunDirs[0].Name)) already completed — nothing to resume. Omit -Resume to start a fresh scan."
        }
        throw "-Resume specified but no previous run.jsonl was found under $OutputRoot"
    }

    Write-Host "Resuming: $($resumeRunDirs.Count) run(s) in this attempt's chain — already-scanned repositories from any of them will be skipped."
    # Pass the exact run directory names, not the whole output root — the scan script unions
    # repositoryIds across only these, so an old fully-completed scan sitting in the same
    # output directory never gets pulled into an unrelated later resume.
    return ($resumeRunDirs | ForEach-Object { $_.Name }) -join ','
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

Set-Location $ProjectRoot

$RootEnvFile = Join-Path $ProjectRoot '.env'
$ComposeEnvFile = Join-Path $ProjectRoot 'docker\ops\.env'

if (-not (Test-Path $RootEnvFile)) {
    throw "Root .env file not found at $RootEnvFile. Copy .env.example to .env first (see README)."
}

# Root .env supplies shared settings (proxy/TLS); docker/ops/.env supplies
# ops-specific settings and overrides the same keys if both define them.
$EnvFileArgs = @('--env-file', $RootEnvFile, '--env-file', $ComposeEnvFile)

try {
    # Always rebuild (Docker's layer cache makes this a fast no-op when nothing changed)
    # so a plain run never silently uses a stale image after a `git pull`. -Rebuild forces
    # a clean --no-cache build and re-exports host CA certs; neither is required just to
    # pick up ordinary Dockerfile/entrypoint/collect-sboms.sh/collect-static-analysis.sh changes.
    if ($Rebuild) {
        Write-Host "Exporting host CA certificates..."
        Export-HostCertificates -OutputDir (Join-Path $ProjectRoot '.docker/certs')
    }
    Write-Host "Building ops image..."
    if ($Rebuild) {
        Invoke-Docker compose @EnvFileArgs build ops --no-cache
    } else {
        Invoke-Docker compose @EnvFileArgs build ops
    }

    $isGitHubCredentialSet = $PSCmdlet.ParameterSetName -in @('Shell', 'Claude')
    $isAzdoCredentialSet = $PSCmdlet.ParameterSetName -in @('SbomScan', 'StaticAnalysis')

    # Inject -Credential/-Name/-Organization/etc. into the PS environment so Docker Compose
    # picks them up via ${GITHUB_TOKEN:-}, ${GIT_USER_EMAIL:-}, ${AZDO_PAT:-}, ...
    # Wiped in the outer finally block regardless of how the script exits.
    if ($isGitHubCredentialSet) {
        if ($Credential) {
            $env:GIT_USER_EMAIL = $Credential.UserName
            $env:GITHUB_TOKEN   = $Credential.GetNetworkCredential().Password
        } else {
            # Reuse the GitHub PAT already stored in appsec-scout's credential vault (the GitHub
            # tracker's token) instead of requiring it to be re-entered in docker/ops/.env.
            $vaultGitHubToken = Get-SystemVaultCredential -Key 'github.token' -EnvFileArgs $EnvFileArgs
            if ($vaultGitHubToken) {
                Write-Host "Using GitHub token from appsec-scout's credential vault (GitHub tracker)."
                $env:GITHUB_TOKEN = $vaultGitHubToken
            }
        }
        if (-not [string]::IsNullOrWhiteSpace($Name)) {
            $env:GIT_USER_NAME = $Name
        }
    } elseif ($isAzdoCredentialSet) {
        if ($Credential) {
            $env:AZDO_PAT = $Credential.GetNetworkCredential().Password
        } else {
            # Reuse the PAT already stored in appsec-scout's credential vault (the AzDO source's
            # PAT) instead of requiring it to be re-entered in docker/ops/.env for every scan.
            $vaultAzdoPat = Get-SystemVaultCredential -Key 'azdo.pat' -EnvFileArgs $EnvFileArgs
            if ($vaultAzdoPat) {
                Write-Host "Using AzDO PAT from appsec-scout's credential vault (AzDO Advanced Security source)."
                $env:AZDO_PAT = $vaultAzdoPat
            }
            if ([string]::IsNullOrWhiteSpace($Organization)) {
                $vaultAzdoOrg = Get-SystemVaultCredential -Key 'azdo.organization' -EnvFileArgs $EnvFileArgs
                if ($vaultAzdoOrg) {
                    Write-Host "Using AzDO organization from appsec-scout's credential vault: $vaultAzdoOrg"
                    $env:AZDO_ORG = $vaultAzdoOrg
                }
            }
        }
        if (-not [string]::IsNullOrWhiteSpace($Organization)) {
            $env:AZDO_ORG = $Organization
        }
        if (-not [string]::IsNullOrWhiteSpace($ProjectFilter)) {
            $env:AZDO_PROJECT_FILTER = $ProjectFilter
        }
        if (-not [string]::IsNullOrWhiteSpace($RepositoryFilter)) {
            $env:AZDO_REPO_FILTER = $RepositoryFilter
        }
        if (-not [string]::IsNullOrWhiteSpace($OutputDir)) {
            if ($PSCmdlet.ParameterSetName -eq 'StaticAnalysis') {
                $env:STATIC_ANALYSIS_OUTPUT_DIR = $OutputDir
            } else {
                $env:SBOM_OUTPUT_DIR = $OutputDir
            }
        }
    }

    # Build env-override args for Repo/Branch flags
    $envOverrides = @()
    if (-not [string]::IsNullOrWhiteSpace($Repo)) {
        $envOverrides += '-e'; $envOverrides += "REPO_URL=$Repo"
    }
    if (-not [string]::IsNullOrWhiteSpace($Branch)) {
        $envOverrides += '-e'; $envOverrides += "REPO_BRANCH=$Branch"
    }
    if ($PSCmdlet.ParameterSetName -in @('SbomScan', 'StaticAnalysis') -and $SkipUpload) {
        # Tells collect-sboms.sh/collect-static-analysis.sh to drop a .skip-import marker
        # in this run's output directory, so the matching scheduled *:import-pending-scans
        # tick (which runs every minute independently of this script) never imports a
        # dry-run scan either. Both scripts read the same AZDO_SKIP_IMPORT env var.
        $envOverrides += '-e'; $envOverrides += 'AZDO_SKIP_IMPORT=1'
    }

    switch ($PSCmdlet.ParameterSetName) {
        'Shell' {
            Write-Host "Starting ops shell. Type 'exit' to quit."
            Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides ops
        }
        'Claude' {
            if ($Login) {
                Write-Host "Starting OAuth login — your browser will open. Complete the flow, then type /exit."
                Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides ops --login
                Write-Host "Login complete. Credentials saved to the 'claude_credentials' Docker volume."
                # A failed login throws above (Invoke-Docker) and is caught by the outer
                # try/catch, which exits non-zero before ever reaching -Task below.
            }
            if ($Task) {
                $env:CLAUDE_TASK = $Task
                try {
                    Write-Host "Running task: $Task"
                    Invoke-Docker compose @EnvFileArgs run --rm --no-deps -e CLAUDE_TASK @envOverrides ops --claude-task
                } finally {
                    Remove-Item Env:\CLAUDE_TASK -ErrorAction SilentlyContinue
                }
            } elseif (-not $Login) {
                Write-Host "Starting interactive Claude session. Type /exit to quit."
                Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides ops --claude-shell
            }
        }
        'SbomScan' {
            $resolvedOutputRoot = if (-not [string]::IsNullOrWhiteSpace($OutputDir)) {
                $OutputDir
            } else {
                Join-Path $ProjectRoot 'output\sbom-scan'
            }

            if ($Resume) {
                # SBOM_OUTPUT_DIR is bind-mounted to /output in the ops container (docker-compose.yml).
                $resumeRunNames = Resolve-ResumeRunNames -OutputRoot $resolvedOutputRoot
                $envOverrides += '-e'; $envOverrides += "RESUME_FROM=$resumeRunNames"
            }

            Write-Host "Starting SBOM scan. This runs to completion in one container session..."
            Invoke-Docker compose @EnvFileArgs run --rm --no-deps @envOverrides ops --sbom-scan

            $latestRun = Get-ChildItem -Path $resolvedOutputRoot -Directory -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if ($latestRun) {
                Write-Host "SBOM output: $($latestRun.FullName)"

                if ($SkipUpload) {
                    Write-Host "Skipping upload to appsec-scout (-SkipUpload)."
                } else {
                    Invoke-SbomUpload -EnvFileArgs $EnvFileArgs
                }
            } else {
                Write-Warning "SBOM scan finished but no output directory was found under $resolvedOutputRoot"
            }
        }
        'StaticAnalysis' {
            $resolvedOutputRoot = if (-not [string]::IsNullOrWhiteSpace($OutputDir)) {
                $OutputDir
            } else {
                Join-Path $ProjectRoot 'output\static-analysis-scan'
            }

            if ($Resume) {
                # STATIC_ANALYSIS_OUTPUT_DIR is bind-mounted to /output-static-analysis in the
                # ops container (docker-compose.yml).
                $resumeRunNames = Resolve-ResumeRunNames -OutputRoot $resolvedOutputRoot
                $envOverrides += '-e'; $envOverrides += "RESUME_FROM=$resumeRunNames"
            }

            Write-Host "Starting static analysis scan. This runs to completion in one container session..."
            Invoke-Docker compose @EnvFileArgs run --rm --no-deps @envOverrides ops --static-analysis

            $latestRun = Get-ChildItem -Path $resolvedOutputRoot -Directory -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if ($latestRun) {
                Write-Host "Static analysis output: $($latestRun.FullName)"

                if ($SkipUpload) {
                    Write-Host "Skipping upload to appsec-scout (-SkipUpload)."
                } else {
                    Invoke-StaticAnalysisUpload -EnvFileArgs $EnvFileArgs
                }
            } else {
                Write-Warning "Static analysis scan finished but no output directory was found under $resolvedOutputRoot"
            }
        }
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    # GITHUB_TOKEN/AZDO_PAT/AZDO_ORG are removed unconditionally: either the explicit
    # parameter set them, or the vault lookup did — either way they must not linger in the
    # host PowerShell session's environment once this script exits.
    Remove-Item Env:\GITHUB_TOKEN, Env:\AZDO_PAT -ErrorAction SilentlyContinue
    if ($Credential -and $PSCmdlet.ParameterSetName -in @('Shell', 'Claude')) {
        Remove-Item Env:\GIT_USER_EMAIL -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        Remove-Item Env:\GIT_USER_NAME -ErrorAction SilentlyContinue
    }
    Remove-Item Env:\AZDO_ORG -ErrorAction SilentlyContinue
    if (-not [string]::IsNullOrWhiteSpace($ProjectFilter)) {
        Remove-Item Env:\AZDO_PROJECT_FILTER -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($RepositoryFilter)) {
        Remove-Item Env:\AZDO_REPO_FILTER -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($OutputDir)) {
        Remove-Item Env:\SBOM_OUTPUT_DIR, Env:\STATIC_ANALYSIS_OUTPUT_DIR -ErrorAction SilentlyContinue
    }
    $ErrorActionPreference = $SavedErrorActionPreference
}
