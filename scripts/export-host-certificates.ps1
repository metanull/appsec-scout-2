<#
.SYNOPSIS
    Exports the host's trusted CA certificates into .docker/certs/.
.DESCRIPTION
    Standalone wrapper around Export-HostCertificates (scripts/lib/Certificates.psm1):
    exports the host's trusted root and intermediate CAs into .docker/certs/ in the
    layout the containers and dependencytrack-cacerts-init expect — one
    NNNN-<label>-<THUMBPRINT>.crt file per certificate plus a combined
    host-ca-bundle.crt. Safe to re-run: existing *.crt files in the output directory
    are replaced with the current export. Windows only (reads the Cert:\ drive); use
    scripts/export-host-certificates.sh on Linux hosts.

    scripts/appsec-scout.ps1 and scripts/invoke-ops.ps1 already call
    Export-HostCertificates directly and do not need this script. It exists for
    operators running the deployment bundle (docs/QUICKSTART.md), who have the
    scripts/ directory but not the rest of the repository.
.PARAMETER OutputDir
    Directory to write the exported certificates into. Defaults to .docker/certs
    resolved relative to the repository root (the parent of scripts/).
.EXAMPLE
    .\scripts\export-host-certificates.ps1
    Exports the host's trusted CAs into .docker/certs.
.EXAMPLE
    .\scripts\export-host-certificates.ps1 -OutputDir C:\temp\certs
    Exports the host's trusted CAs into a custom directory.
#>
[CmdletBinding()]
param(
    [string]$OutputDir = (Join-Path (Split-Path $PSScriptRoot -Parent) '.docker/certs')
)

$ErrorActionPreference = 'Stop'
$InformationPreference = 'Continue'

Import-Module (Join-Path $PSScriptRoot 'lib/Certificates.psm1') -Force

Export-HostCertificates -OutputDir $OutputDir

$resolvedOutputDir = [System.IO.Path]::GetFullPath($OutputDir)
$certificateCount = @(Get-ChildItem -Path $resolvedOutputDir -File -Filter '*.crt' -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match '^\d{4}-.+\.crt$' }).Count
Write-Information "Wrote $certificateCount certificate file(s) to $resolvedOutputDir"
