$PSScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ErrorActionPreference = "Stop"

try {
    Set-Location (Split-Path $PSScriptRoot)

    $env:APP_BUILD_TARGET = "dev"

    docker compose ps | Out-Null
    if( $LASTEXITCODE -ne 0) {
        throw "Docker compose is not running. Please start Docker and try again."
    }

    Copy-Item app-laravel/.env.example .env
    $appKey = docker compose run --rm app php artisan key:generate --show
    if( $LASTEXITCODE -ne 0) {
        throw "Failed to generate application key. Please check your Docker setup and try again."
    }
    (Get-Content .env) -replace '^APP_KEY=.*', "APP_KEY=$appKey" | Set-Content .env
    docker compose up --build -d
    if( $LASTEXITCODE -ne 0) {
        throw "Failed to start Docker containers. Please check your Docker setup and try again."
    }
    docker compose ps
    if( $LASTEXITCODE -ne 0) {
        throw "Failed to list Docker containers. Please check your Docker setup and try again."
    }
    docker compose exec app php artisan migrate --force
    if( $LASTEXITCODE -ne 0) {
        throw "Failed to run database migrations. Please check your Docker setup and try again."
    }
    docker compose exec app php artisan db:seed
    if( $LASTEXITCODE -ne 0) {
        throw "Failed to seed the database. Please check your Docker setup and try again."
    }
    docker compose exec app php artisan appsec:bootstrap-admin --name="Admin" --email="admin@example.com" --password="changeme-now"
    if( $LASTEXITCODE -ne 0) {
        throw "Failed to bootstrap admin user. Please check your Docker setup and try again."
    }
    $Reply = Invoke-WebRequest http://localhost:8080/up
    if( $Reply.StatusCode -ne 200) {
        throw "Failed to assert that the application is up."
    }
    Start-Process "http://localhost:8080"
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
}