<#
.SYNOPSIS
    Shared helpers for exporting trusted host CA certificates into .docker/certs,
    used by appsec-scout.ps1 and invoke-ops.ps1 so corporate proxy/TLS-intercepting
    setups work identically across every container.
#>

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

    $storePaths = @('Cert:\LocalMachine\Root', 'Cert:\LocalMachine\CA', 'Cert:\CurrentUser\Root', 'Cert:\CurrentUser\CA')
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

    $bundlePath = Join-Path $resolvedOutputDir 'host-ca-bundle.crt'
    $bundleBuilder = [System.Text.StringBuilder]::new()
    $index = 0
    foreach ($thumbprint in ($certificatesByThumbprint.Keys | Sort-Object)) {
        $index++
        $certificate = $certificatesByThumbprint[$thumbprint]
        $pem = Convert-ToPem -RawData $certificate.RawData
        $fileName = Get-SafeName -Certificate $certificate -Index $index
        $filePath = Join-Path $resolvedOutputDir $fileName
        [System.IO.File]::WriteAllText($filePath, $pem + [Environment]::NewLine)
        [void]$bundleBuilder.AppendLine($pem)
    }
    [System.IO.File]::WriteAllText($bundlePath, $bundleBuilder.ToString())
    Write-Information ("Exported {0} trusted certificates to {1}" -f $certificatesByThumbprint.Count, $resolvedOutputDir)
}

Export-ModuleMember -Function Convert-ToPem, Get-SafeName, Export-HostCertificates
