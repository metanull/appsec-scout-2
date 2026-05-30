#!/usr/bin/env pwsh
# Run the Filament feature test suite via Docker (no build required).
# Uses the php:8.4-cli image with required extensions installed at runtime.
#
# Usage:
#   .\scripts\run-filament-tests.ps1
#   .\scripts\run-filament-tests.ps1 tests/Feature/Filament/AuditLogDetailTest.php

param(
    [string]$Filter = "tests/Feature/Filament/"
)

$root = Split-Path $PSScriptRoot -Parent
$appDir = Join-Path $root "app-laravel"

$cmd = @"
apt-get update -qq && apt-get install -y -q libicu-dev > /dev/null 2>&1 &&
docker-php-ext-install -j4 pdo_mysql pcntl sockets intl > /dev/null 2>&1 &&
php vendor/pestphp/pest/bin/pest $Filter --no-coverage 2>&1
"@ -replace "`n", " "

docker run --rm `
  --network appsec-scout-2_default `
  -e DB_CONNECTION=mysql `
  -e DB_HOST=mysql `
  -e DB_PORT=3306 `
  -e DB_DATABASE=appsec_scout `
  -e DB_USERNAME=appsec_scout `
  -e DB_PASSWORD=password `
  -e APP_ENV=testing `
  -e APP_KEY=base64:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa= `
  -e CACHE_STORE=array `
  -e SESSION_DRIVER=array `
  -e QUEUE_CONNECTION=sync `
  -e LOG_STACK=stderr `
  -v "${appDir}:/app" `
  -w /app `
  php:8.4-cli `
  bash -c $cmd
