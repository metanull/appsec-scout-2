<#
.SYNOPSIS
    This script manages the lifecycle of the AppSec Scout application using Docker Compose. It can start the application, rebuild it from scratch, and ensure that it's up and running before opening it in the browser.
.DESCRIPTION
    The script checks if Docker Compose is available, exports trusted host CA certificates into
    .docker/certs when present, builds the app, collector, and static-analysis-collector images,
    starts the containers —
    including Dependency-Track and its bundled Trivy analyzer server, which start with the app
    by default, not as an opt-in profile — runs database migrations and seeds the database,
    bootstraps an admin user with known credentials for testing purposes, imports system
    credentials when present, waits for Dependency-Track's one-shot bootstrap (team/API key/
    Trivy analyzer provisioning) to finish, and finally opens the application in the browser.
    Dependency-Track and Trivy require no manual setup: the shared secret between them is
    generated inside the stack on first start.
    In prebuilt-image mode (the docker-compose.ghcr.yml override, activated through COMPOSE_FILE
    in the root .env), the script pulls the published GHCR images instead of building, and
    re-exports the host CA certificates on every run, since they are consumed at container start
    rather than at image-build time. Everything else behaves the same.
.PARAMETER Rebuild
    If specified, stops and removes existing containers, volumes, and orphans (wiping the
    database and all app state) and re-exports host CA certificates before rebuilding and
    starting the application. Use this for a clean slate, not just to pick up code changes —
    every run already rebuilds the app, collector, and static-analysis-collector images
    (respecting Docker's layer cache) before starting, so plain `.\appsec-scout.ps1` alone is
    enough to pick up any source, dependency, or Dockerfile change without losing data.
.PARAMETER Force
    Skips Docker's build cache for the app, collector, and static-analysis-collector images on
    this run (`--no-cache`). Independent of -Rebuild — use it alone if you suspect a stale cache
    layer, without wiping any data. Has no effect in prebuilt-image mode (nothing is built).
.EXAMPLE
    .\appsec-scout.ps1
    Rebuilds the app, collector, and static-analysis-collector images (cache permitting) and
    starts the application, preserving all data.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild
    Wipes all containers/volumes/data, re-exports host CA certs, then rebuilds and starts fresh.
.EXAMPLE
    .\appsec-scout.ps1 -Force
    Rebuilds the app, collector, and static-analysis-collector images from scratch (no cache) and
    starts the application, preserving all data.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild -Force
    Wipes all data and rebuilds the app, collector, and static-analysis-collector images from
    scratch before starting.
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

Import-Module (Join-Path $MyScriptRoot 'lib/Certificates.psm1') -Force

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

Function Test-PullMode {
    # Prebuilt-image mode: the docker-compose.ghcr.yml override (activated through
    # COMPOSE_FILE in the root .env) removes the app service's build section and
    # repoints it at the published GHCR image. Detect it from the merged compose
    # config rather than parsing COMPOSE_FILE, so any way of layering the override
    # (env var, --file flags in COMPOSE_FILE, future renames) is recognized.
    $config = (Invoke-Docker compose config --format json) -join "`n" | ConvertFrom-Json
    return -not $config.services.app.PSObject.Properties['build']
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

if (-not (Test-Docker)) {
    throw "Docker does not seem to be available or running."
}
try {
    $pullMode = Test-PullMode

    if ($Rebuild.IsPresent -and $Rebuild) {
        Invoke-Docker compose down --volumes --remove-orphans
    }

    # Build mode consumes the exported certificates at image-build time, so refreshing
    # them only matters on -Rebuild. Pull mode never builds — the certificates are
    # consumed at container start (see docker/entrypoint.sh, /host-certs) — so every
    # run re-exports them to keep the runtime trust store current.
    if ($pullMode -or ($Rebuild.IsPresent -and $Rebuild)) {
        Export-HostCertificates -OutputDir (Join-Path $ProjectRoot '.docker/certs')
    }

    # Build mode: always rebuild the app, collector, and static-analysis-collector images
    # (Docker's layer cache makes this a fast no-op when nothing changed) so a plain run never
    # silently starts a stale image after a `git pull` — `docker compose up` alone only builds
    # an image the first time it's missing, it never rebuilds an existing one just because its
    # Dockerfile changed. Pull mode: pull instead, so a moving tag (e.g. latest) is refreshed
    # the same way; pinned sha- tags resolve to a fast no-op.
    if ($pullMode) {
        if ($Force.IsPresent -and $Force) {
            Write-Host "-Force has no effect in prebuilt-image mode (nothing is built); ignoring it."
        }
        Invoke-Docker compose pull app collector static-analysis-collector
    } elseif ($Force.IsPresent -and $Force) {
        Invoke-Docker compose build app collector static-analysis-collector --no-cache
    } else {
        Invoke-Docker compose build app collector static-analysis-collector
    }

    Invoke-Docker compose up -d

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