<#
.SYNOPSIS
    This script manages the lifecycle of the AppSec Scout application using Docker Compose. It can start the application, rebuild it from scratch, and ensure that it's up and running before opening it in the browser.
.DESCRIPTION
    The script checks if Docker Compose is running, and if the -Rebuild switch is provided, it will stop and remove existing containers, volumes, and orphans to ensure a clean rebuild. It then copies the example environment file, generates a new application key, builds and starts the Docker containers, runs database migrations and seeds the database. Additionally, it bootstraps an admin user with known credentials for testing purposes. If a .credentials.json file exists in the current directory, it copies it into the container and imports the system credentials. Finally, it asserts that the application is up and running before opening it in the browser.
.PARAMETER Rebuild
    If specified, the script will perform a clean rebuild of the application by stopping and removing existing Docker containers, volumes, and orphans, copying the example environment file, generating a new application key, building and starting the Docker containers, running database migrations and seeding the database, and bootstrapping an admin user with known credentials for testing purposes. If not specified, the script will simply start the existing Docker containers without rebuilding.
.EXAMPLE
    .\appsec-scout.ps1
    Starts the AppSec Scout application using Docker Compose without rebuilding.
.EXAMPLE
    .\appsec-scout.ps1 -Rebuild
    Performs a clean rebuild of the AppSec Scout application using Docker Compose, including stopping and removing existing containers, volumes, and orphans, copying the example environment file, generating a new application key, building and starting the Docker containers, running database migrations and seeding the database, and bootstrapping an admin user with known credentials for testing purposes.
#>
[CmdletBinding()]
param(
    [Switch]$Rebuild
)
$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Stop"
Set-Location (Split-Path $MyScriptRoot)
$env:APP_BUILD_TARGET = "dev"
try {
    docker compose ps | Out-Null
    if( $LASTEXITCODE -ne 0) {
        throw "Docker compose is not running. Please start Docker and try again."
    }

    if ($Rebuild.IsPresent -and $Rebuild) {
        # Stop and remove existing containers, volumes, and orphans to ensure a clean rebuild
        docker compose down --volumes --remove-orphans
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to stop and remove existing Docker containers. Please check your Docker setup and try again."
        }
        # Copy the example environment file and generate a new application key
        Copy-Item app-laravel/.env.example .env
        docker compose build app --quiet
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to build the app image. Please check your Docker setup and try again."
        }
        docker compose run --rm bootstrap-cache-init
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to initialize the bootstrap/cache volume. Please check your Docker setup and try again."
        }
        $appKey = docker compose run --rm --no-deps app php artisan key:generate --show
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to generate application key. Please check your Docker setup and try again."
        }
        (Get-Content .env) -replace '^APP_KEY=.*', "APP_KEY=$appKey" | Set-Content .env
        # Build and start the Docker containers
        docker compose up --build -d
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to start Docker containers. Please check your Docker setup and try again."
        }
        # Run database migrations and seed the database
        docker compose exec app php artisan migrate --force
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to run database migrations. Please check your Docker setup and try again."
        }
        docker compose exec app php artisan db:seed
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to seed the database. Please check your Docker setup and try again."
        }

        # Bootstrap an admin user with known credentials for testing purposes
        docker compose exec app php artisan appsec:bootstrap-admin --if-missing --name="Admin" --email="admin@example.com" --password="changeme-now"
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to bootstrap admin user. Please check your Docker setup and try again."
        }
        # If a .credentials.json file exists in the current directory, copy it into the container and import the system credentials
        if (Test-Path ".credentials.json") {
            docker compose cp .credentials.json app:/var/www/html/storage/app/private/credentials.json
            if ($LASTEXITCODE -ne 0) {
                throw "Failed to copy .credentials.json into the app container."
            }
            docker compose exec app php artisan credentials:system:import /var/www/html/storage/app/private/credentials.json
            if ($LASTEXITCODE -ne 0) {
                throw "Failed to import system credentials from copied JSON file."
            }
            Write-Information "Imported system credentials from .credentials.json"
        }
    } else {
        # Stop the existing containers to ensure a clean start without rebuilding
        docker compose down
        # Start the Docker containers without rebuilding
        docker compose up -d
        if( $LASTEXITCODE -ne 0) {
            throw "Failed to start Docker containers. Please check your Docker setup and try again."
        }
        Start-Sleep -Seconds 3
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
    # Clean up the environment variable to avoid side effects on other scripts or commands
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
    # Restore the original error action preference to avoid side effects on other scripts or commands
    $ErrorActionPreference = $SavedErrorActionPreference
}