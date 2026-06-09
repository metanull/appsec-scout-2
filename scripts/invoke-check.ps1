<#
    .SYNOPSIS
        Runs various checks (linting, static analysis, tests, etc.) against the Laravel application using Docker Compose.
    .DESCRIPTION
        This script provides a convenient way to run different types of checks against the Laravel application in a
        consistent environment using Docker Compose. By default, it runs all checks, but you can specify individual checks
        or groups of checks using the -Check parameter. The script also ensures that the necessary environment variables are set and that the application is built before running the checks. If any check fails, the script will throw an error with a descriptive message.
    .PARAMETER Check
        Selects which tests to run; by default, all read-only checks are run. Specify one or more of: pint, phpstan, pest-sqlite, pest-mysql, composer-smoke, dependencies, dependencies-fix.
    .EXAMPLE
        .\invoke-check.ps1
        Runs all checks (linting, static analysis, tests, etc.) against the Laravel application using Docker Compose.
    .EXAMPLE
        .\invoke-check.ps1 -Check lint
        Runs only the linting checks (Pint) against the Laravel application using Docker Compose.
#>
[CmdletBinding()]
param(
    # Selects which tests to run; by default, all tests are run. Specify one or more of: pint, phpstan, pest-sqlite, pest-mysql, composer-smoke
    [Parameter(Mandatory = $false)]
    [ValidateSet('all', 'lint', 'lint-fix', 'test', 'test-sqlite', 'test-mysql', 'static-analysis', 'smoke', 'dependencies', 'dependencies-fix')]
    [string]$Check = 'all'
)
$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Stop"
Set-Location (Split-Path $MyScriptRoot)
$env:APP_BUILD_TARGET = "dev"
$envTestingPath    = "app-laravel/.env.testing"
$envExamplePath    = "app-laravel/.env.testing.example"
try {
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

    <#
    # Build the app image before running any checks to ensure the environment is ready for testing.
    docker compose build app --quiet
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to build the dev app image."
    }
    #>
    docker compose run --rm bootstrap-cache-init
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to initialize the bootstrap/cache volume."
    }


    if ($Check -eq 'lint-fix') {
        $workspacePath = (Get-Location).Path.Replace('\\', '/') + '/app-laravel'

        docker compose run --rm --no-deps -u root -v "${workspacePath}:/var/www/html" app vendor/bin/pint
        if ($LASTEXITCODE -ne 0) {
            throw "Pint fix check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'lint') {
         docker compose run --rm app vendor/bin/pint --test
         if ($LASTEXITCODE -ne 0) {
             throw "Pint check failed."
         }
    }

    if ($Check -eq 'all' -or $Check -eq 'static-analysis') {
         docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
         if ($LASTEXITCODE -ne 0) {
             throw "PHPStan check failed."
         }
    }

    if ($Check -eq 'all' -or $Check -eq 'test' -or $Check -eq 'test-sqlite') {
        # Phase 1: SQLite (in-memory).
        # phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory: via force="true",
        # overriding the mysql settings that arrive from .env.testing.
        docker compose run --rm @testEnvArgs app vendor/bin/pest --no-coverage --compact
        if ($LASTEXITCODE -ne 0) {
            throw "Pest (SQLite) check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'test' -or $Check -eq 'test-mysql') {
        # Phase 2: MySQL (dedicated appsec_scout_test database).
        # The database is created automatically by docker/mysql-init.sql on first MySQL
        # start; artisan migrate:fresh handles the schema — no manual SQL required.
        <#
        docker compose up -d mysql redis
        if ($LASTEXITCODE -ne 0) {
            throw "Failed to start MySQL/Redis for MySQL test phase."
        }

        docker compose run --rm @testEnvArgs app php artisan migrate:fresh --force
        if ($LASTEXITCODE -ne 0) {
            throw "artisan migrate:fresh failed for appsec_scout_test."
        }
        #>

        docker compose run --rm @testEnvArgs app vendor/bin/pest --no-coverage --configuration phpunit.mysql.xml --compact
        if ($LASTEXITCODE -ne 0) {
            throw "Pest (MySQL) check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'smoke') {
        docker compose run --rm @testEnvArgs app composer smoke
        if ($LASTEXITCODE -ne 0) {
            throw "Composer smoke check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'dependencies') {
        docker compose run --rm @testEnvArgs app composer outdated --strict
        if ($LASTEXITCODE -ne 0) {
            throw "Composer dependencies check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'dependencies-fix') {
        $workspacePath = (Get-Location).Path.Replace('\\', '/') + '/app-laravel'

        docker compose run --rm --no-deps -u root -v "${workspacePath}:/var/www/html" app sh -c "mkdir -p bootstrap/cache && COMPOSER_CACHE_DIR=/tmp/composer-cache composer update --no-scripts --with-dependencies"
        if ($LASTEXITCODE -ne 0) {
            throw "Composer dependencies fix failed."
        }
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
    $ErrorActionPreference = $SavedErrorActionPreference
}
