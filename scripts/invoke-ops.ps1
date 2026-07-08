<#
.SYNOPSIS
    Opens an appsec-ops shell in a sandboxed Docker container.
.DESCRIPTION
    Launches the ops container for hands-on appsec investigation: code analysis,
    repository archaeology, secret scanning, dependency auditing, and history
    cleaning. The container opens an interactive bash shell and never auto-launches
    Claude, though 'claude' is available inside if authenticated.
    Use -Mode login once to authenticate Claude via your Pro/Max subscription.
.PARAMETER Mode
    shell     — Interactive bash shell (default). Clones repo first if OPS_REPO_URL is set.
    login     — One-time OAuth login; saves credentials to the 'claude_credentials' volume.
    sbom-scan — Collects SBOMs (CycloneDX, via Trivy) from every repository in an Azure
                DevOps organization, restoring/building any *.sln first for precise .NET
                results. Runs to completion in a single container invocation; each repo is
                deleted immediately after it is scanned. Output lands on the host under
                SBOM_OUTPUT_DIR (default .\output\sbom-scan\<timestamp>\). Reports are
                uploaded into appsec-scout as Attachments on the matching SoftwareSystem/
                SecurityContainer incrementally, as soon as each repository finishes — a
                scheduled `sbom:import-pending-scans` tick in the `app` container picks up
                every new run.jsonl line every minute, so you don't have to wait for the
                whole (possibly multi-hour) scan to see results land. This script also
                triggers that same command once more right after the scan container exits,
                to flush anything the last scheduled tick hasn't picked up yet. Pass
                -SkipUpload to opt this run out of both entirely (see -SkipUpload).
.PARAMETER Repo
    GitHub HTTPS URL to clone. Overrides OPS_REPO_URL from .env.
.PARAMETER Branch
    Branch to clone. Overrides OPS_REPO_BRANCH from .env.
.PARAMETER Name
    Git display name used in commits (e.g. "Pascal HAVELANGE"). Overrides GIT_USER_NAME from .env.
.PARAMETER Credential
    GitHub credential for cloning private repositories.
    UserName = git commit email — overrides GIT_USER_EMAIL from .env.
    Password = GitHub PAT      — overrides GITHUB_TOKEN from .env.
    If omitted, the GitHub PAT already configured as appsec-scout's GitHub tracker
    credential is fetched from the running `app` container and reused; if that isn't
    available either, the container falls back to GITHUB_TOKEN from docker/ops/.env.
    Tip: pass (Get-Credential) for an interactive prompt, or retrieve a stored entry
    from Windows Credential Manager with Get-StoredCredential (module CredentialManager).
.PARAMETER Organization
    Azure DevOps organization to scan (-Mode sbom-scan). Overrides AZDO_ORG from .env.
.PARAMETER AzdoCredential
    Azure DevOps credential (-Mode sbom-scan). Password = PAT with "Code (Read)" scope
    across the organization — overrides AZDO_PAT from .env. UserName is not used.
    If omitted, the PAT (and organization) already configured as appsec-scout's AzDO
    Advanced Security source credential is fetched from the running `app` container and
    reused; if that isn't available either, the container falls back to AZDO_PAT/AZDO_ORG
    from docker/ops/.env.
.PARAMETER ProjectFilter
    Regex applied to project names (-Mode sbom-scan). Overrides AZDO_PROJECT_FILTER from .env.
.PARAMETER RepositoryFilter
    Regex applied to repository names (-Mode sbom-scan). Overrides AZDO_REPO_FILTER from .env.
.PARAMETER OutputDir
    Host directory to receive SBOM output (-Mode sbom-scan). Overrides SBOM_OUTPUT_DIR from .env.
.PARAMETER SkipUpload
    Skip uploading generated SBOMs into appsec-scout as attachments (-Mode sbom-scan).
    Files still land under OutputDir either way. This also prevents the per-minute
    scheduled import (sbom:import-pending-scans) from picking up this run, since that
    runs independently of this script — collect-sboms.sh drops a marker in the run's
    output directory that the scheduled command checks for and skips.
.PARAMETER Rebuild
    Forces a clean --no-cache rebuild of the ops image and re-exports host CA certificates.
    Not required to pick up ordinary code changes — every run already rebuilds the image
    (respecting Docker's layer cache), so a stale image is never used just because -Rebuild
    was omitted.
.EXAMPLE
    .\invoke-ops.ps1 -Mode login
.EXAMPLE
    .\invoke-ops.ps1
.EXAMPLE
    .\invoke-ops.ps1 -Repo https://github.com/org/repo.git -Branch main
.EXAMPLE
    .\invoke-ops.ps1 -Repo https://github.com/org/repo.git -Credential (Get-Credential) -Name "Pascal HAVELANGE"
.EXAMPLE
    .\invoke-ops.ps1 -Mode sbom-scan -AzdoCredential (Get-Credential)
.EXAMPLE
    .\invoke-ops.ps1 -Mode sbom-scan -AzdoCredential (Get-Credential) -ProjectFilter '^Portal$'
#>
[CmdletBinding()]
param(
    [ValidateSet('shell', 'login', 'sbom-scan')]
    [string]$Mode = 'shell',

    [string]$Repo = '',

    [string]$Branch = '',

    [string]$Name = '',

    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential,

    [string]$Organization = '',

    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $AzdoCredential,

    [string]$ProjectFilter = '',

    [string]$RepositoryFilter = '',

    [string]$OutputDir = '',

    [Switch]$SkipUpload,

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
        Write-Warning "sbom:import-pending-scans reported a failure; check appsec-scout's Error Log for details."
    }
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
    # pick up ordinary Dockerfile/entrypoint/collect-sboms.sh changes.
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

    # Inject -Credential/-Name/-AzdoCredential/etc. into the PS environment so Docker
    # Compose picks them up via ${GITHUB_TOKEN:-}, ${GIT_USER_EMAIL:-}, ${AZDO_PAT:-}, ...
    # Wiped in the outer finally block regardless of how the script exits.
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
    if ($AzdoCredential) {
        $env:AZDO_PAT = $AzdoCredential.GetNetworkCredential().Password
    } elseif ($Mode -eq 'sbom-scan') {
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
        $env:SBOM_OUTPUT_DIR = $OutputDir
    }

    # Build env-override args for Repo/Branch flags
    $envOverrides = @()
    if (-not [string]::IsNullOrWhiteSpace($Repo)) {
        $envOverrides += '-e'; $envOverrides += "REPO_URL=$Repo"
    }
    if (-not [string]::IsNullOrWhiteSpace($Branch)) {
        $envOverrides += '-e'; $envOverrides += "REPO_BRANCH=$Branch"
    }
    if ($Mode -eq 'sbom-scan' -and $SkipUpload) {
        # Tells collect-sboms.sh to drop a .skip-import marker in this run's output
        # directory, so the scheduled sbom:import-pending-scans tick (which runs every
        # minute independently of this script) never imports a dry-run scan either.
        $envOverrides += '-e'; $envOverrides += 'AZDO_SKIP_IMPORT=1'
    }

    switch ($Mode) {
        'login' {
            Write-Host "Starting OAuth login — your browser will open. Complete the flow, then type /exit."
            Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides ops --login
            Write-Host "Login complete. Credentials saved to the 'claude_credentials' Docker volume."
        }
        'shell' {
            Write-Host "Starting ops shell. Type 'exit' to quit."
            Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides ops
        }
        'sbom-scan' {
            Write-Host "Starting SBOM scan. This runs to completion in one container session..."
            Invoke-Docker compose @EnvFileArgs run --rm --no-deps @envOverrides ops --sbom-scan

            $resolvedOutputRoot = if (-not [string]::IsNullOrWhiteSpace($OutputDir)) {
                $OutputDir
            } else {
                Join-Path $ProjectRoot 'output\sbom-scan'
            }
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
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    # GITHUB_TOKEN/AZDO_PAT/AZDO_ORG are removed unconditionally: either the explicit
    # parameter set them, or the vault lookup did — either way they must not linger in the
    # host PowerShell session's environment once this script exits.
    Remove-Item Env:\GITHUB_TOKEN, Env:\AZDO_PAT -ErrorAction SilentlyContinue
    if ($Credential) {
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
        Remove-Item Env:\SBOM_OUTPUT_DIR -ErrorAction SilentlyContinue
    }
    $ErrorActionPreference = $SavedErrorActionPreference
}
