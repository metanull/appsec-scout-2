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
    shell  — Interactive bash shell (default). Clones repo first if OPS_REPO_URL is set.
    login  — One-time OAuth login; saves credentials to the 'claude_credentials' volume.
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
    If omitted, the container falls back to those .env values.
    Tip: pass (Get-Credential) for an interactive prompt, or retrieve a stored entry
    from Windows Credential Manager with Get-StoredCredential (module CredentialManager).
.PARAMETER Rebuild
    Export host CA certificates and rebuild the ops Docker image before running.
.EXAMPLE
    .\invoke-ops.ps1 -Mode login
.EXAMPLE
    .\invoke-ops.ps1
.EXAMPLE
    .\invoke-ops.ps1 -Repo https://github.com/org/repo.git -Branch main
.EXAMPLE
    .\invoke-ops.ps1 -Repo https://github.com/org/repo.git -Credential (Get-Credential) -Name "Pascal HAVELANGE"
#>
[CmdletBinding()]
param(
    [ValidateSet('shell', 'login')]
    [string]$Mode = 'shell',

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
# Helpers (cert export logic mirrors invoke-claude.ps1)
# ---------------------------------------------------------------------------

function Convert-ToPem {
    param([byte[]]$RawData)
    $base64 = [Convert]::ToBase64String($RawData)
    $lines = for ($offset = 0; $offset -lt $base64.Length; $offset += 64) {
        $length = [Math]::Min(64, $base64.Length - $offset)
        $base64.Substring($offset, $length)
    }
    return @('-----BEGIN CERTIFICATE-----'; $lines; '-----END CERTIFICATE-----') -join [Environment]::NewLine
}

function Get-SafeName {
    param(
        [System.Security.Cryptography.X509Certificates.X509Certificate2]$Certificate,
        [int]$Index
    )
    $label = if ($Certificate.FriendlyName) { $Certificate.FriendlyName }
             else { $Certificate.GetNameInfo([System.Security.Cryptography.X509Certificates.X509NameType]::SimpleName, $false) }
    if ([string]::IsNullOrWhiteSpace($label)) { $label = 'certificate' }
    $safeLabel = ($label -replace '[^A-Za-z0-9._-]+', '-').Trim('-')
    if ([string]::IsNullOrWhiteSpace($safeLabel)) { $safeLabel = 'certificate' }
    return '{0:D4}-{1}-{2}.crt' -f $Index, $safeLabel, $Certificate.Thumbprint.ToUpperInvariant()
}

function Export-HostCertificates {
    param([string]$OutputDir)
    $resolvedOutputDir = [System.IO.Path]::GetFullPath($OutputDir)
    New-Item -ItemType Directory -Path $resolvedOutputDir -Force | Out-Null
    Get-ChildItem -Path $resolvedOutputDir -File -Filter '*.crt' -ErrorAction SilentlyContinue | Remove-Item -Force

    $storePaths = @('Cert:\LocalMachine\Root','Cert:\LocalMachine\CA','Cert:\CurrentUser\Root','Cert:\CurrentUser\CA')
    $certificatesByThumbprint = @{}
    foreach ($storePath in $storePaths) {
        if (-not (Test-Path $storePath)) { continue }
        foreach ($certificate in Get-ChildItem -Path $storePath) {
            if (-not $certificate.RawData -or [string]::IsNullOrWhiteSpace($certificate.Thumbprint)) { continue }
            $thumbprint = $certificate.Thumbprint.ToUpperInvariant()
            if (-not $certificatesByThumbprint.ContainsKey($thumbprint)) {
                $certificatesByThumbprint[$thumbprint] = $certificate
            }
        }
    }

    if ($certificatesByThumbprint.Count -eq 0) {
        Write-Information "No trusted host CA certificates found; continuing without extra exports."
        return
    }

    $bundlePath    = Join-Path $resolvedOutputDir 'host-ca-bundle.crt'
    $bundleBuilder = [System.Text.StringBuilder]::new()
    $index = 0
    foreach ($thumbprint in ($certificatesByThumbprint.Keys | Sort-Object)) {
        $index++
        $certificate = $certificatesByThumbprint[$thumbprint]
        $pem      = Convert-ToPem -RawData $certificate.RawData
        $fileName = Get-SafeName -Certificate $certificate -Index $index
        $filePath = Join-Path $resolvedOutputDir $fileName
        [System.IO.File]::WriteAllText($filePath, $pem + [Environment]::NewLine)
        [void]$bundleBuilder.AppendLine($pem)
    }
    [System.IO.File]::WriteAllText($bundlePath, $bundleBuilder.ToString())
    Write-Information ("Exported {0} trusted certificates to {1}" -f $certificatesByThumbprint.Count, $resolvedOutputDir)
}

function Invoke-Docker {
    docker @args
    if ($LASTEXITCODE -ne 0) {
        throw ("`docker $($args -join ' ')` failed with exit code $LASTEXITCODE")
    }
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

Set-Location $ProjectRoot

$ComposeEnvFile = Join-Path $ProjectRoot 'docker\ops\.env'

try {
    # Build image when requested or when it doesn't exist yet
    $imageExists = (docker image inspect 'appsec-scout-ops:latest' 2>$null) -ne $null -and $LASTEXITCODE -eq 0
    if ($Rebuild -or -not $imageExists) {
        Write-Host "Exporting host CA certificates..."
        Export-HostCertificates -OutputDir (Join-Path $ProjectRoot '.docker/certs')
        Write-Host "Building ops image..."
        if ($Rebuild) {
            Invoke-Docker compose --env-file $ComposeEnvFile build ops --no-cache
        } else {
            Invoke-Docker compose --env-file $ComposeEnvFile build ops
        }
    }

    # Inject -Credential and -Name into the PS environment so Docker Compose
    # picks them up via ${GITHUB_TOKEN:-}, ${GIT_USER_EMAIL:-}, ${GIT_USER_NAME:-}.
    # Wiped in the outer finally block regardless of how the script exits.
    if ($Credential) {
        $env:GIT_USER_EMAIL = $Credential.UserName
        $env:GITHUB_TOKEN   = $Credential.GetNetworkCredential().Password
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
            Invoke-Docker compose --env-file $ComposeEnvFile run --rm -it --no-deps @envOverrides ops --login
            Write-Host "Login complete. Credentials saved to the 'claude_credentials' Docker volume."
        }
        'shell' {
            Write-Host "Starting ops shell. Type 'exit' to quit."
            Invoke-Docker compose --env-file $ComposeEnvFile run --rm -it --no-deps @envOverrides ops
        }
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    if ($Credential) {
        Remove-Item Env:\GIT_USER_EMAIL, Env:\GITHUB_TOKEN -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        Remove-Item Env:\GIT_USER_NAME -ErrorAction SilentlyContinue
    }
    $ErrorActionPreference = $SavedErrorActionPreference
}
