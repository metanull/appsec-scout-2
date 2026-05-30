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

    # Phase 1: SQLite (in-memory, no external services required)
    docker compose run --rm -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array app vendor/bin/pest --no-coverage
    if ($LASTEXITCODE -ne 0) {
        throw "Pest (SQLite) check failed."
    }

    # Phase 2: MySQL (dedicated appsec_scout_test database; keeps the live appsec_scout DB untouched)
    docker compose up -d mysql redis
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to start MySQL/Redis for MySQL test phase."
    }

    # Wait for mysql health through the app dependency chain, then create the test database once.
    docker compose run --rm app php -v | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to wait for MySQL health before MySQL test phase."
    }

    $rootPassword = if ($env:DB_ROOT_PASSWORD) { $env:DB_ROOT_PASSWORD } else { "rootpassword" }
    $sql = "CREATE DATABASE IF NOT EXISTS appsec_scout_test; GRANT ALL PRIVILEGES ON appsec_scout_test.* TO 'appsec_scout'@'%'; FLUSH PRIVILEGES;"
    docker compose exec mysql mysql "-uroot" "--password=$rootPassword" "-e" $sql 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to create MySQL test database appsec_scout_test."
    }

    docker compose run --rm -e APP_ENV=testing -e DB_CONNECTION=mysql -e DB_DATABASE=appsec_scout_test -e DB_URL= -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array app vendor/bin/pest --no-coverage --configuration phpunit.mysql.xml
    if ($LASTEXITCODE -ne 0) {
        throw "Pest (MySQL) check failed."
    }

    docker compose run --rm -e APP_ENV=testing -e DB_CONNECTION=mysql -e DB_DATABASE=appsec_scout_test -e DB_URL= -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array app composer smoke
    if ($LASTEXITCODE -ne 0) {
        throw "Composer smoke check failed."
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
}