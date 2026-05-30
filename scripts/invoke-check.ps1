$PSScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ErrorActionPreference = "Stop"

try {
    Set-Location (Split-Path $PSScriptRoot)

    $env:APP_BUILD_TARGET = "dev"

    docker compose build app
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to build the dev app image."
    }

    docker compose run --rm app vendor/bin/pint --test
    if ($LASTEXITCODE -ne 0) {
        throw "Pint check failed."
    }

    docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
    if ($LASTEXITCODE -ne 0) {
        throw "PHPStan check failed."
    }

    docker compose run --rm app vendor/bin/pest --no-coverage
    if ($LASTEXITCODE -ne 0) {
        throw "Pest check failed."
    }

    docker compose run --rm app composer smoke
    if ($LASTEXITCODE -ne 0) {
        throw "Composer smoke check failed."
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
}