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
                SBOM_OUTPUT_DIR (default .\output\sbom-scan\<timestamp>\). Every successful
                SBOM is then uploaded into appsec-scout as an Attachment on the matching
                SoftwareSystem/SecurityContainer (via `assets:import-attachment` in the
                `app` container) unless -SkipUpload is passed.
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
.PARAMETER Organization
    Azure DevOps organization to scan (-Mode sbom-scan). Overrides AZDO_ORG from .env.
.PARAMETER AzdoCredential
    Azure DevOps credential (-Mode sbom-scan). Password = PAT with "Code (Read)" scope
    across the organization — overrides AZDO_PAT from .env. UserName is not used.
.PARAMETER ProjectFilter
    Regex applied to project names (-Mode sbom-scan). Overrides AZDO_PROJECT_FILTER from .env.
.PARAMETER RepositoryFilter
    Regex applied to repository names (-Mode sbom-scan). Overrides AZDO_REPO_FILTER from .env.
.PARAMETER OutputDir
    Host directory to receive SBOM output (-Mode sbom-scan). Overrides SBOM_OUTPUT_DIR from .env.
.PARAMETER SkipUpload
    Skip uploading generated SBOMs into appsec-scout as attachments (-Mode sbom-scan).
    Files still land under OutputDir either way.
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

function Invoke-SbomUpload {
    param(
        [Parameter(Mandatory)][string]$ComposeEnvFile,
        [Parameter(Mandatory)][System.IO.DirectoryInfo]$RunDirectory
    )

    $summaryPath = Join-Path $RunDirectory.FullName 'summary.json'
    if (-not (Test-Path $summaryPath)) {
        Write-Warning "No summary.json found under $($RunDirectory.FullName); skipping upload."
        return
    }

    $summary = Get-Content $summaryPath -Raw | ConvertFrom-Json

    # One report kind per Trivy scan type; each maps to its own attachment kind
    # and gets parsed automatically server-side (assets:import-attachment ->
    # AttachmentIngestionService) into SoftwareComponent/LocalFinding rows.
    $reportKinds = @(
        @{ Generated = 'sbomGenerated'; Path = 'sbomPath'; AttachmentKind = 'sbom'; Label = 'SBOM' }
        @{ Generated = 'vulnerabilitiesGenerated'; Path = 'vulnerabilitiesPath'; AttachmentKind = 'vulnerabilities'; Label = 'vulnerability report' }
        @{ Generated = 'secretsGenerated'; Path = 'secretsPath'; AttachmentKind = 'secrets'; Label = 'secret report' }
    )

    $uploads = @()
    foreach ($reportKind in $reportKinds) {
        foreach ($result in $summary.results) {
            if ($result.($reportKind.Generated) -eq $true -and -not [string]::IsNullOrWhiteSpace($result.($reportKind.Path))) {
                $uploads += [pscustomobject]@{
                    Result = $result
                    Path = $result.($reportKind.Path)
                    AttachmentKind = $reportKind.AttachmentKind
                    Label = $reportKind.Label
                }
            }
        }
    }

    if ($uploads.Count -eq 0) {
        Write-Host "No reports to upload."
        return
    }

    Write-Host "Uploading $($uploads.Count) report(s) to appsec-scout..."

    # `exec` reads the container's mount as it was when the container was (re)created, so make
    # sure `app` is up to date with the current SBOM_OUTPUT_DIR bind mount before uploading.
    docker compose --env-file $ComposeEnvFile up -d app | Out-Null

    $uploaded = 0
    $failed = 0

    foreach ($upload in $uploads) {
        $result = $upload.Result
        $containerPath = "/var/www/html/sbom-import/$($RunDirectory.Name)/$($upload.Path)".Replace('\', '/')

        docker compose --env-file $ComposeEnvFile exec -T app php artisan assets:import-attachment `
            azdo $result.projectId $upload.AttachmentKind $containerPath `
            --container $result.repositoryId `
            --system-name $result.project `
            --container-name $result.repository `
            --container-kind repository | Out-Null

        if ($LASTEXITCODE -eq 0) {
            $uploaded++
        } else {
            $failed++
            Write-Warning "Failed to upload $($upload.Label) for $($result.project)/$($result.repository)."
        }
    }

    $suffix = if ($failed -gt 0) { " ($failed failed — see warnings above; run 'docker compose up -d app' if uploads report a missing file)" } else { '' }
    Write-Host "Uploaded $uploaded of $($uploads.Count) report(s) to appsec-scout.$suffix"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

Set-Location $ProjectRoot

$ComposeEnvFile = Join-Path $ProjectRoot 'docker\ops\.env'

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
        Invoke-Docker compose --env-file $ComposeEnvFile build ops --no-cache
    } else {
        Invoke-Docker compose --env-file $ComposeEnvFile build ops
    }

    # Inject -Credential/-Name/-AzdoCredential/etc. into the PS environment so Docker
    # Compose picks them up via ${GITHUB_TOKEN:-}, ${GIT_USER_EMAIL:-}, ${AZDO_PAT:-}, ...
    # Wiped in the outer finally block regardless of how the script exits.
    if ($Credential) {
        $env:GIT_USER_EMAIL = $Credential.UserName
        $env:GITHUB_TOKEN   = $Credential.GetNetworkCredential().Password
    }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        $env:GIT_USER_NAME = $Name
    }
    if ($AzdoCredential) {
        $env:AZDO_PAT = $AzdoCredential.GetNetworkCredential().Password
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
        'sbom-scan' {
            Write-Host "Starting SBOM scan. This runs to completion in one container session..."
            Invoke-Docker compose --env-file $ComposeEnvFile run --rm --no-deps @envOverrides ops --sbom-scan

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
                    Invoke-SbomUpload -ComposeEnvFile $ComposeEnvFile -RunDirectory $latestRun
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
    if ($Credential) {
        Remove-Item Env:\GIT_USER_EMAIL, Env:\GITHUB_TOKEN -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($Name)) {
        Remove-Item Env:\GIT_USER_NAME -ErrorAction SilentlyContinue
    }
    if ($AzdoCredential) {
        Remove-Item Env:\AZDO_PAT -ErrorAction SilentlyContinue
    }
    if (-not [string]::IsNullOrWhiteSpace($Organization)) {
        Remove-Item Env:\AZDO_ORG -ErrorAction SilentlyContinue
    }
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
