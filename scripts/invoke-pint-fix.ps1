$PSScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ErrorActionPreference = "Stop"

try {
    Set-Location (Split-Path $PSScriptRoot)

    $envTestingPath    = "app-laravel/.env.testing"
    $envExamplePath    = "app-laravel/.env.testing.example"

    if (-not (Test-Path $envTestingPath)) {
        Copy-Item $envExamplePath $envTestingPath
    }

    $hasKey = (Select-String -Path $envTestingPath -Pattern '^APP_KEY=.+' -Quiet)
    if (-not $hasKey) {
        $key = "base64:" + [Convert]::ToBase64String(
            [System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
        (Get-Content $envTestingPath) -replace '^APP_KEY=.*', "APP_KEY=$key" |
            Set-Content $envTestingPath
    }

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

    $workspacePath = (Get-Location).Path.Replace('\\', '/') + '/app-laravel'

    docker compose run --rm --no-deps -u root -v "${workspacePath}:/var/www/html" @testEnvArgs app vendor/bin/pint
    if ($LASTEXITCODE -ne 0) {
        throw "Pint fix run failed."
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    Remove-Item Env:\APP_BUILD_TARGET -ErrorAction SilentlyContinue
}
