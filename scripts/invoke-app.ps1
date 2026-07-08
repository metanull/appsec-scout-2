<#
.SYNOPSIS
    Interacts with the already-running `app` container: shell, restart, tinker, or artisan.
.DESCRIPTION
    Thin wrapper around `docker compose exec`/`restart` for day-to-day operation of the live
    appsec-scout app container started via appsec-scout.ps1. Never rebuilds or recreates
    anything — it only attaches to (or restarts) the existing service, so it's safe to use
    without risking the database or any other container state.
.PARAMETER Restart
    Restarts the `app` container (`docker compose restart app`) instead of exec'ing into it.
    Re-runs the entrypoint's boot sequence (migrations, seeding, asset resync, permission
    chown, etc.) without recreating the container or touching any volume.
.PARAMETER Tinker
    Opens an interactive `php artisan tinker` session in the running container.
.PARAMETER Artisan
    Runs `php artisan <args>` in the running container and exits. Takes all remaining
    arguments verbatim as the artisan command and its own arguments/options.
.EXAMPLE
    .\invoke-app.ps1
    Opens an interactive bash shell in the `app` container.
.EXAMPLE
    .\invoke-app.ps1 -Restart
.EXAMPLE
    .\invoke-app.ps1 -Tinker
.EXAMPLE
    .\invoke-app.ps1 -Artisan credentials:system:get azdo.pat
.EXAMPLE
    .\invoke-app.ps1 -Artisan migrate:status
#>
[CmdletBinding()]
param(
    [Switch]$Restart,

    [Switch]$Tinker,

    [Switch]$Artisan,

    [Parameter(Position = 0, ValueFromRemainingArguments = $true)]
    [string[]]$ArtisanArgs = @()
)

$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectRoot  = Split-Path $MyScriptRoot
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Stop'

function Invoke-Docker {
    docker @args
    if ($LASTEXITCODE -ne 0) {
        throw ("`docker $($args -join ' ')` failed with exit code $LASTEXITCODE")
    }
}

Set-Location $ProjectRoot

try {
    $modesRequested = @($RestartApp.IsPresent, $Tinker.IsPresent, $Artisan.IsPresent) | Where-Object { $_ }
    if ($modesRequested.Count -gt 1) {
        throw "Specify only one of -RestartApp, -Tinker, or -Artisan at a time."
    }
    if ($Artisan -and $ArtisanArgs.Count -eq 0) {
        throw "-Artisan requires at least one artisan command argument, e.g. -Artisan migrate:status"
    }

    if ($RestartApp) {
        Write-Host "Restarting app container..."
        Invoke-Docker compose restart app
    } elseif ($Tinker) {
        Write-Host "Starting artisan tinker in the app container. Type 'exit' to quit."
        Invoke-Docker compose exec app php artisan tinker
    } elseif ($Artisan) {
        Invoke-Docker compose exec app php artisan @ArtisanArgs
    } else {
        Write-Host "Starting bash shell in the app container. Type 'exit' to quit."
        Invoke-Docker compose exec app bash
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    $ErrorActionPreference = $SavedErrorActionPreference
}