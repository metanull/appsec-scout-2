<#
.SYNOPSIS
    This script manages the lifecycle of the AppSec Scout application using Docker Compose. It can start the application, rebuild it from scratch, and ensure that it's up and running before opening it in the browser.
.DESCRIPTION
    The script checks if Docker Compose is available, exports trusted host CA certificates into .docker/certs when present, builds the app image, starts the containers, runs database migrations and seeds the database, bootstraps an admin user with known credentials for testing purposes, imports system credentials when present, and finally opens the application in the browser.
.PARAMETER Rebuild
    If specified, the script will perform a clean rebuild of the application by stopping and removing existing Docker containers, volumes, and orphans before rebuilding and starting the application.
.PARAMETER Force
    When used in conjunction with -Rebuild, this parameter forces the rebuild process to skip cached docker layers.
.EXAMPLE
    .\appsec-scout.ps1
    Starts the AppSec Scout application using pre-built Docker image.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild
    (Re)build the AppSec Scout docker image and perform and start the application.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild -Force
    Force a rebuild of the AppSec Scout docker image and start the application, even if there are potential issues detected.
#>
[CmdletBinding()]
param(
    [Switch]$Rebuild,
    [Switch]$Force
)
$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectRoot = Split-Path $MyScriptRoot
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Stop"

function Convert-ToPem {
    param(
        [byte[]]$RawData
    )

    $base64 = [Convert]::ToBase64String($RawData)
    $lines = for ($offset = 0; $offset -lt $base64.Length; $offset += 64) {
        $length = [Math]::Min(64, $base64.Length - $offset)
        $base64.Substring($offset, $length)
    }

    return @(
        '-----BEGIN CERTIFICATE-----'
        $lines
        '-----END CERTIFICATE-----'
    ) -join [Environment]::NewLine
}

function Get-SafeName {
    param(
        [System.Security.Cryptography.X509Certificates.X509Certificate2]$Certificate,
        [int]$Index
    )

    $label = if ($Certificate.FriendlyName) {
        $Certificate.FriendlyName
    }
    else {
        $Certificate.GetNameInfo([System.Security.Cryptography.X509Certificates.X509NameType]::SimpleName, $false)
    }

    if ([string]::IsNullOrWhiteSpace($label)) {
        $label = 'certificate'
    }

    $safeLabel = ($label -replace '[^A-Za-z0-9._-]+', '-').Trim('-')
    if ([string]::IsNullOrWhiteSpace($safeLabel)) {
        $safeLabel = 'certificate'
    }

    return '{0:D4}-{1}-{2}.crt' -f $Index, $safeLabel, $Certificate.Thumbprint.ToUpperInvariant()
}

function Export-HostCertificates {
    param(
        [string]$OutputDir
    )

    $resolvedOutputDir = [System.IO.Path]::GetFullPath($OutputDir)
    New-Item -ItemType Directory -Path $resolvedOutputDir -Force | Out-Null
    Get-ChildItem -Path $resolvedOutputDir -File -Filter '*.crt' -ErrorAction SilentlyContinue | Remove-Item -Force

    $storePaths = @(
        'Cert:\LocalMachine\Root',
        'Cert:\LocalMachine\CA',
        'Cert:\CurrentUser\Root',
        'Cert:\CurrentUser\CA'
    )

    $certificatesByThumbprint = @{}

    foreach ($storePath in $storePaths) {
        if (-not (Test-Path $storePath)) {
            continue
        }

        foreach ($certificate in Get-ChildItem -Path $storePath) {
            if (-not $certificate.RawData -or [string]::IsNullOrWhiteSpace($certificate.Thumbprint)) {
                continue
            }

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
    Write-Verbose ("Bundle written to {0}" -f $bundlePath)
}

Function Invoke-Docker {
    docker @args
    if ($LASTEXITCODE -ne 0) {
        throw ("{0}$($args -join ' '){0} failed with exit code $LASTEXITCODE" -f '`')
    }
}

Function Test-Docker {
    try {
        Invoke-Docker version --format '{{.Server.Version}}' | Out-Null
        Invoke-Docker compose version | Out-Null
        return $true
    } catch {
        return $false
    }
}

Function Wait-DockerServiceHealthy {
    param(
        [Parameter(Mandatory)]
        [string]$ServiceName,

        [int]$MaxRetries = 10,
        [int]$SleepTimeSeconds = 3
    )
    # Wait for the database container to be healthy before running migrations
    $retryCount = 0
    while ($retryCount -lt $MaxRetries) {
        Start-Sleep -Seconds $SleepTimeSeconds
        try {
            $containerId = Invoke-Docker compose ps -q $ServiceName
            if ($containerId) {
                $serviceStatus = Invoke-Docker inspect -f '{{.State.Health.Status}}' $containerId
                if ($serviceStatus.Trim() -eq 'healthy') {
                    return $true
                }
            }
            Write-Verbose "Service '$ServiceName' is not running. (attempt $($retryCount + 1) of $MaxRetries)"
        } catch {
            Write-Verbose "Error while checking the health of service '$ServiceName': $_"
        } finally {
            $retryCount++
        }
    }
    return false;
}

Set-Location $ProjectRoot

if (-not (Test-Docker)) {
    throw "Docker does not seem to be available or running."
}
try {
    if ($Rebuild.IsPresent -and $Rebuild) {
        Invoke-Docker compose down --volumes --remove-orphans
        if ($Force.IsPresent -and $Force) {
            Invoke-Docker compose build app --no-cache
        } else {
            Invoke-Docker compose build app
        }

        Export-HostCertificates -OutputDir (Join-Path $ProjectRoot '.docker/certs')
    }
    Invoke-Docker compose up -d

    # Wait for the database container to be healthy before running migrations
    if( -not (Wait-DockerServiceHealthy -ServiceName "mysql")) {
        throw "Database container did not become healthy within the expected time. Please check your Docker setup and try again."
    }

    if ($Rebuild.IsPresent -and $Rebuild) {
        Invoke-Docker compose exec app php artisan migrate --force
        Invoke-Docker compose exec app php artisan db:seed
        Invoke-Docker compose exec app php artisan appsec:bootstrap-admin --if-missing --name='Admin' --email='admin@example.com' --password='changeme-now'

        if (Test-Path ".credentials.json") {
            Invoke-Docker compose cp .credentials.json app:/var/www/html/storage/app/private/credentials.json
            Invoke-Docker compose exec app php artisan credentials:system:import /var/www/html/storage/app/private/credentials.json
            Write-Information "Imported system credentials from .credentials.json"
        }
    } else {
        Invoke-Docker compose exec app composer dump-autoload --optimize
        Invoke-Docker compose exec app php artisan migrate
        Invoke-Docker compose exec app php artisan cache:clear
    }

    # Assert that the application is up and running before attempting to open it in the browser
    $Reply = Invoke-WebRequest http://localhost:8080/up
    if( $Reply.StatusCode -ne 200) {
        throw "Failed to assert that the application is up."
    }
    Start-Process "http://localhost:8080"
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    # Restore the original error action preference to avoid side effects on other scripts or commands
    $ErrorActionPreference = $SavedErrorActionPreference
}