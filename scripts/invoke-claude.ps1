<#
.SYNOPSIS
    Runs Claude Code in a sandboxed Docker container.
.DESCRIPTION
    Launches Claude Code inside an isolated, ephemeral container with no access to the host
    filesystem. In task mode the container clones the specified repo branch, runs the task
    autonomously, and submits the result as a GitHub PR. In shell mode an interactive Claude
    session is opened. Use -Mode login once to authenticate via your Pro/Max subscription.
.PARAMETER Mode
    shell  — Interactive Claude session (default). Clones repo first if CLAUDE_REPO_URL is set.
    login  — One-time OAuth login; saves credentials to the persistent 'claude_credentials' volume.
    task   — Autonomous run: clone repo, execute -Task, push branch, open PR.
.PARAMETER Task
    The prompt/task description for Claude (required when -Mode task).
.PARAMETER Repo
    GitHub HTTPS URL to clone. Overrides CLAUDE_REPO_URL from .env.
.PARAMETER Branch
    Branch to clone and use as the PR base. Overrides CLAUDE_REPO_BRANCH from .env.
.PARAMETER Name
    Git display name used in commits (e.g. "Pascal HAVELANGE"). Overrides GIT_USER_NAME from .env.
.PARAMETER Credential
    GitHub credential for pushing branches and creating PRs.
    UserName = git commit email — overrides GIT_USER_EMAIL from .env.
    Password = GitHub PAT      — overrides GITHUB_TOKEN from .env.
    If omitted, the GitHub PAT already configured as appsec-scout's GitHub tracker
    credential is fetched from the running `app` container and reused; if that isn't
    available either, the container falls back to GITHUB_TOKEN from docker/claude/.env.
    Tip: pass (Get-Credential) for an interactive prompt, or retrieve a stored entry
    from Windows Credential Manager with Get-StoredCredential (module CredentialManager).
.PARAMETER Rebuild
    Forces a clean --no-cache rebuild of the claude image and re-exports host CA certificates.
    Not required to pick up ordinary code changes — every run already rebuilds the image
    (respecting Docker's layer cache), so a stale image is never used just because -Rebuild
    was omitted.
.EXAMPLE
    .\invoke-claude.ps1 -Mode login
.EXAMPLE
    .\invoke-claude.ps1
.EXAMPLE
    .\invoke-claude.ps1 -Mode task -Task "Add input validation to the SecurityEvent edit form"
.EXAMPLE
    .\invoke-claude.ps1 -Mode task -Task "..." -Repo https://github.com/org/repo.git -Branch feature/x
.EXAMPLE
    .\invoke-claude.ps1 -Mode task -Task "..." -Credential (Get-Credential) -Name "Pascal HAVELANGE"
#>
[CmdletBinding()]
param(
    [ValidateSet('shell', 'login', 'task')]
    [string]$Mode = 'shell',

    [string]$Task = '',

    [string]$Repo = '',

    [string]$Branch = '',

    [string]$Name = '',

    [System.Management.Automation.Credential()]
    [System.Management.Automation.PSCredential]
    $Credential,

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
    Fetches a single system credential already configured in appsec-scout (e.g. the GitHub
    tracker's PAT) via the running `app` container, so the same token doesn't have to be
    re-entered into this container's own .env file. Silently returns $null (never throws) if
    `app` isn't running or the credential isn't configured — callers fall back to their own
    env var in that case.
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

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

Set-Location $ProjectRoot

$RootEnvFile = Join-Path $ProjectRoot '.env'
$ComposeEnvFile = Join-Path $ProjectRoot 'docker\claude\.env'

if (-not (Test-Path $RootEnvFile)) {
    throw "Root .env file not found at $RootEnvFile. Copy .env.example to .env first (see README)."
}

# Root .env supplies shared settings (proxy/TLS); docker/claude/.env supplies
# claude-specific settings and overrides the same keys if both define them.
$EnvFileArgs = @('--env-file', $RootEnvFile, '--env-file', $ComposeEnvFile)

try {
    # Validate -Task is provided for task mode
    if ($Mode -eq 'task' -and [string]::IsNullOrWhiteSpace($Task)) {
        throw "'-Task' is required when using '-Mode task'."
    }

    # Always rebuild (Docker's layer cache makes this a fast no-op when nothing changed)
    # so a plain run never silently uses a stale image after a `git pull`. -Rebuild forces
    # a clean --no-cache build and re-exports host CA certs; neither is required just to
    # pick up ordinary Dockerfile/entrypoint.sh changes.
    if ($Rebuild) {
        Write-Host "Exporting host CA certificates..."
        Export-HostCertificates -OutputDir (Join-Path $ProjectRoot '.docker/certs')
    }
    Write-Host "Building claude image..."
    if ($Rebuild) {
        Invoke-Docker compose @EnvFileArgs build claude --no-cache
    } else {
        Invoke-Docker compose @EnvFileArgs build claude
    }

    # Inject -Credential and -Name into the PS environment so Docker Compose
    # picks them up via ${GITHUB_TOKEN:-}, ${GIT_USER_EMAIL:-}, ${GIT_USER_NAME:-}.
    # Wiped in the outer finally block regardless of how the script exits.
    if ($Credential) {
        $env:GIT_USER_EMAIL = $Credential.UserName
        $env:GITHUB_TOKEN   = $Credential.GetNetworkCredential().Password
    } else {
        # Reuse the GitHub PAT already stored in appsec-scout's credential vault (the GitHub
        # tracker's token) instead of requiring it to be re-entered in docker/claude/.env.
        $vaultToken = Get-SystemVaultCredential -Key 'github.token' -EnvFileArgs $EnvFileArgs
        if ($vaultToken) {
            Write-Host "Using GitHub token from appsec-scout's credential vault (GitHub tracker)."
            $env:GITHUB_TOKEN = $vaultToken
        }
    }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        $env:GIT_USER_NAME = $Name
    }

    # Build env-override args for Repo/Branch flags
    $envOverrides = @()
    if (-not [string]::IsNullOrWhiteSpace($Repo)) {
        $envOverrides += '-e'; $envOverrides += "REPO_URL=$Repo"
    }
    if (-not [string]::IsNullOrWhiteSpace($Branch)) {
        $envOverrides += '-e'; $envOverrides += "REPO_BRANCH=$Branch"
    }

    switch ($Mode) {
        'login' {
            Write-Host "Starting OAuth login — your browser will open. Complete the flow, then type /exit."
            Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides claude --login
            Write-Host "Login complete. Credentials saved to the 'claude_credentials' Docker volume."
        }
        'shell' {
            Write-Host "Starting interactive Claude session. Type /exit to quit."
            Invoke-Docker compose @EnvFileArgs run --rm -it --no-deps @envOverrides claude --shell
        }
        'task' {
            # Pass the task via environment to avoid shell-quoting issues
            $env:CLAUDE_TASK = $Task
            try {
                Write-Host "Running task: $Task"
                Invoke-Docker compose @EnvFileArgs run --rm --no-deps -e CLAUDE_TASK @envOverrides claude
            } finally {
                Remove-Item Env:\CLAUDE_TASK -ErrorAction SilentlyContinue
            }
        }
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\GITHUB_TOKEN -ErrorAction SilentlyContinue
    if ($Credential) {
        Remove-Item Env:\GIT_USER_EMAIL -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        Remove-Item Env:\GIT_USER_NAME -ErrorAction SilentlyContinue
    }
    $ErrorActionPreference = $SavedErrorActionPreference
}
