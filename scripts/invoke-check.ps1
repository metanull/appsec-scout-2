$PSScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ErrorActionPreference = "Stop"

try {
    Set-Location (Split-Path $PSScriptRoot)

    $envTestingPath    = "app-laravel/.env.testing"
    $envExamplePath    = "app-laravel/.env.testing.example"

    # Ensure .env.testing exists; copy from the committed example when it is missing.
    if (-not (Test-Path $envTestingPath)) {
        Copy-Item $envExamplePath $envTestingPath
    }

    # Generate APP_KEY when absent (equivalent to artisan key:generate; done before
    # the Docker build so the key is stable across all phases of this run).
    $hasKey = (Select-String -Path $envTestingPath -Pattern '^APP_KEY=.+' -Quiet)
    if (-not $hasKey) {
        $key = "base64:" + [Convert]::ToBase64String(
            [System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
        (Get-Content $envTestingPath) -replace '^APP_KEY=.*', "APP_KEY=$key" |
            Set-Content $envTestingPath
    }

    # Build the -e argument list from .env.testing so that every docker compose run
    # command below receives the test environment without hardcoded values.
    $testEnvArgs = @()
    Get-Content $envTestingPath |
        Where-Object { $_ -match '^[A-Za-z_]\w*=' } |
        ForEach-Object {
            $testEnvArgs += "-e"
            $testEnvArgs += $_
        }

    $env:APP_BUILD_TARGET = "dev"

    docker compose build app --quiet
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to build the dev app image."
    }

    docker compose run --rm app vendor/bin/pint --test --quiet
    if ($LASTEXITCODE -ne 0) {
        throw "Pint check failed."
    }

    docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
    if ($LASTEXITCODE -ne 0) {
        throw "PHPStan check failed."
    }

    # Phase 1: SQLite (in-memory).
    # phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory: via force="true",
    # overriding the mysql settings that arrive from .env.testing.
    docker compose run --rm @testEnvArgs app vendor/bin/pest --no-coverage --compact
    if ($LASTEXITCODE -ne 0) {
        throw "Pest (SQLite) check failed."
    }

    # Phase 2: MySQL (dedicated appsec_scout_test database).
    # The database is created automatically by docker/mysql-init.sql on first MySQL
    # start; artisan migrate:fresh handles the schema — no manual SQL required.
    docker compose up -d mysql redis
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to start MySQL/Redis for MySQL test phase."
    }

    docker compose run --rm @testEnvArgs app php artisan migrate:fresh --force
    if ($LASTEXITCODE -ne 0) {
        throw "artisan migrate:fresh failed for appsec_scout_test."
    }

    docker compose run --rm @testEnvArgs app vendor/bin/pest --no-coverage --configuration phpunit.mysql.xml --compact
    if ($LASTEXITCODE -ne 0) {
        throw "Pest (MySQL) check failed."
    }

    docker compose run --rm @testEnvArgs app composer smoke
    if ($LASTEXITCODE -ne 0) {
        throw "Composer smoke check failed."
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
}
