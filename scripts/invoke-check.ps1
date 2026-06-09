<#
    .SYNOPSIS
        Runs read-only checks (linting, static analysis, tests, etc.) against the Laravel application using Docker Compose.
    .DESCRIPTION
        This script provides a convenient way to run different types of read-only checks against the Laravel application in a
        consistent environment using Docker Compose. By default, it runs all checks, but you can specify individual checks
        or groups of checks using the -Check parameter. The script ensures that the testing environment file exists and then runs
        commands against the already-built app image. If any check fails, the script will throw an error with a descriptive message.
    .PARAMETER Check
        Selects which tests to run; by default, all read-only checks are run. Specify one or more of: lint, test, test-sqlite, test-mysql, static-analysis, smoke, dependencies.
    .EXAMPLE
        .\invoke-check.ps1
        Runs all checks (linting, static analysis, tests, etc.) against the Laravel application using Docker Compose.
    .EXAMPLE
        .\invoke-check.ps1 -Check lint
        Runs only the linting checks (Pint) against the Laravel application using Docker Compose.
#>
[CmdletBinding()]
param(
    # Selects which tests to run; by default, all tests are run. Specify one or more of: lint, test, test-sqlite, test-mysql, static-analysis, smoke, dependencies
    [Parameter(Mandatory = $false)]
    [ValidateSet('all', 'lint', 'test', 'test-sqlite', 'test-mysql', 'static-analysis', 'smoke', 'dependencies')]
    [string]$Check = 'all'
)
$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectRoot = Split-Path $MyScriptRoot
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Stop"
Set-Location $ProjectRoot
$envTestingPath    = "app-laravel/.env.testing"
$envExamplePath    = "app-laravel/.env.testing.example"
try {
    # Ensure .env.testing exists; copy from the committed example when it is missing.
    if (-not (Test-Path $envTestingPath)) {
        Copy-Item $envExamplePath $envTestingPath
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

    if ($Check -eq 'all' -or $Check -eq 'lint') {
         docker compose run --rm --no-build app vendor/bin/pint --test --quiet
         if ($LASTEXITCODE -ne 0) {
             throw "Pint check failed."
         }
    }

    if ($Check -eq 'all' -or $Check -eq 'static-analysis') {
         docker compose run --rm --no-build app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
         if ($LASTEXITCODE -ne 0) {
             throw "PHPStan check failed."
         }
    }

    if ($Check -eq 'all' -or $Check -eq 'test' -or $Check -eq 'test-sqlite') {
        docker compose run --rm --no-build @testEnvArgs app vendor/bin/pest --no-coverage --compact
        if ($LASTEXITCODE -ne 0) {
            throw "Pest (SQLite) check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'test' -or $Check -eq 'test-mysql') {
        docker compose run --rm --no-build @testEnvArgs app vendor/bin/pest --no-coverage --configuration phpunit.mysql.xml --compact
        if ($LASTEXITCODE -ne 0) {
            throw "Pest (MySQL) check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'smoke') {
        docker compose run --rm --no-build @testEnvArgs app composer smoke
        if ($LASTEXITCODE -ne 0) {
            throw "Composer smoke check failed."
        }
    }

    if ($Check -eq 'all' -or $Check -eq 'dependencies') {
        docker compose run --rm --no-build @testEnvArgs app composer outdated --strict
        if ($LASTEXITCODE -ne 0) {
            throw "Composer dependencies check failed."
        }
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    $ErrorActionPreference = $SavedErrorActionPreference
}
