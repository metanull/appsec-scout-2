<#
.SYNOPSIS
    This script manages the lifecycle of the AppSec Scout application using Docker Compose. It can start the application, rebuild it from scratch, and ensure that it's up and running before opening it in the browser.
.DESCRIPTION
    The script ensures .env exists (created from .env.example) and has a TRIVY_SERVER_TOKEN
    (generated if missing), checks if Docker Compose is available, exports trusted host CA
    certificates into .docker/certs when present, builds the app image, starts the containers —
    including the Dependency-Track + Trivy server stack — runs database migrations and seeds
    the database, bootstraps an admin user with known credentials for testing purposes, imports
    system credentials when present, waits for the Dependency-Track bootstrap (team/API
    key/Trivy analyzer provisioning) to finish, and finally opens the application in the browser.
    The only manual step left afterwards is entering source/tracker System Credentials in the UI.
.PARAMETER Rebuild
    If specified, stops and removes existing containers, volumes, and orphans (wiping the
    database and all app state) and re-exports host CA certificates before rebuilding and
    starting the application. Use this for a clean slate, not just to pick up code changes —
    every run already rebuilds the app image (respecting Docker's layer cache) before starting,
    so plain `.\appsec-scout.ps1` alone is enough to pick up any source, dependency, or
    Dockerfile change without losing data.
.PARAMETER Force
    Skips Docker's build cache for the app image on this run (`--no-cache`). Independent of
    -Rebuild — use it alone if you suspect a stale cache layer, without wiping any data.
.EXAMPLE
    .\appsec-scout.ps1
    Rebuilds the app image (cache permitting) and starts the application, preserving all data.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild
    Wipes all containers/volumes/data, re-exports host CA certs, then rebuilds and starts fresh.
.EXAMPLE
    .\appsec-scout.ps1 -Force
    Rebuilds the app image from scratch (no cache) and starts the application, preserving all data.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild -Force
    Wipes all data and rebuilds the app image from scratch before starting.
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

Function New-RandomToken {
    param([int]$Length = 32)
    -join ((48..57) + (65..90) + (97..122) | Get-Random -Count $Length | ForEach-Object { [char]$_ })
}

Function Initialize-DotEnv {
    param(
        [string]$EnvPath,
        [string]$ExamplePath
    )

    if (-not (Test-Path $EnvPath)) {
        Copy-Item $ExamplePath $EnvPath
        Write-Information "Created .env from .env.example."
    }

    $lines = Get-Content $EnvPath
    $tokenLineIndex = -1
    $hasValue = $false

    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^TRIVY_SERVER_TOKEN=(.*)$') {
            $tokenLineIndex = $i
            $hasValue = -not [string]::IsNullOrWhiteSpace($Matches[1])
            break
        }
    }

    if ($hasValue) {
        return
    }

    $token = New-RandomToken
    if ($tokenLineIndex -ge 0) {
        $lines[$tokenLineIndex] = "TRIVY_SERVER_TOKEN=$token"
    } else {
        $lines += "TRIVY_SERVER_TOKEN=$token"
    }
    Set-Content -Path $EnvPath -Value $lines
    Write-Information "Generated a new TRIVY_SERVER_TOKEN in .env — a self-issued shared secret wiring Dependency-Track's Trivy analyzer to the bundled trivy-server container."
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

Function Wait-AppReady {
    param(
        [int]$MaxRetries = 40,
        [int]$SleepTimeSeconds = 5
    )
    $retryCount = 0
    while ($retryCount -lt $MaxRetries) {
        Start-Sleep -Seconds $SleepTimeSeconds
        try {
            $reply = Invoke-WebRequest -Uri "http://localhost:8080/up" -UseBasicParsing -ErrorAction Stop
            if ($reply.StatusCode -eq 200) {
                return $true
            }
        } catch {
            Write-Verbose "App not ready yet (attempt $($retryCount + 1) of $MaxRetries): $_"
        }
        $retryCount++
        # The entrypoint runs composer install, a frontend asset resync, migrations, seeding,
        # and admin bootstrap before nginx ever starts serving — visible progress here avoids
        # this looking hung, especially on a first/cold start.
        if ($retryCount % 6 -eq 0) {
            Write-Host "Still waiting for the app to become ready (attempt $retryCount of $MaxRetries)... check 'docker compose logs app' for entrypoint progress."
        }
    }
    return $false
}

Function Wait-DependencyTrackBootstrap {
    Write-Host "Waiting for Dependency-Track bootstrap (team/API key/Trivy analyzer provisioning) to finish..."
    Invoke-Docker compose wait dependencytrack-bootstrap
}

Set-Location $ProjectRoot

Initialize-DotEnv -EnvPath (Join-Path $ProjectRoot '.env') -ExamplePath (Join-Path $ProjectRoot '.env.example')

if (-not (Test-Docker)) {
    throw "Docker does not seem to be available or running."
}
try {
    if ($Rebuild.IsPresent -and $Rebuild) {
        Invoke-Docker compose --profile dependencytrack down --volumes --remove-orphans
        Export-HostCertificates -OutputDir (Join-Path $ProjectRoot '.docker/certs')
    }

    # Always rebuild the app image (Docker's layer cache makes this a fast no-op when
    # nothing changed) so a plain run never silently starts a stale image after a `git pull`.
    # dependencytrack-bootstrap reuses this same image tag, so no separate build is needed for it.
    if ($Force.IsPresent -and $Force) {
        Invoke-Docker compose build app --no-cache
    } else {
        Invoke-Docker compose build app
    }

    # The dependencytrack profile brings up Postgres, the DT API server/frontend, a bundled
    # Trivy server, and the one-shot bootstrap container that wires them together — every run,
    # not just first boot, so the stack is always fully configured without a manual step.
    Invoke-Docker compose --profile dependencytrack up -d

    # A -Rebuild run wipes the database, so the entrypoint does a full composer install plus
    # migrate/seed/bootstrap-admin from scratch on this start — allow it considerably longer
    # than a warm restart (which only re-verifies already-installed dependencies) before
    # concluding the app is actually stuck rather than still finishing its first boot.
    $waitReady = if ($Rebuild.IsPresent -and $Rebuild) {
        Wait-AppReady -MaxRetries 90 -SleepTimeSeconds 5
    } else {
        Wait-AppReady
    }
    if (-not $waitReady) {
        throw "Application did not become ready within the expected time. Check the container logs with: docker compose logs app"
    }

    Wait-DependencyTrackBootstrap

    if ($Rebuild.IsPresent -and $Rebuild) {
        if (Test-Path ".credentials.json") {
            Invoke-Docker compose cp .credentials.json app:/var/www/html/storage/app/private/credentials.json
            Invoke-Docker compose exec app php artisan credentials:system:import /var/www/html/storage/app/private/credentials.json
            Write-Information "Imported system credentials from .credentials.json"
        }
    }

    Start-Process "http://localhost:8080"
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    # Restore the original error action preference to avoid side effects on other scripts or commands
    $ErrorActionPreference = $SavedErrorActionPreference
}