<#
    .SYNOPSIS
        Runs mutating fix operations against the Laravel application using Docker Compose.
    .DESCRIPTION
        This script runs fix-oriented commands that update the working tree or dependency graph.
        It intentionally avoids read-only checks so that the check and fix workflows stay separate.
    .PARAMETER Fix
        Selects which fix operation to run; by default, all fix operations are run. Specify one or more of: lint-fix, dependencies-fix.
    .EXAMPLE
        .\invoke-fix.ps1
        Runs all fix operations against the Laravel application using Docker Compose.
    .EXAMPLE
        .\invoke-fix.ps1 -Fix lint-fix
        Runs only the Pint formatting fix.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [ValidateSet('all', 'lint-fix', 'dependencies-fix')]
    [string]$Fix = 'all'
)

$MyScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectRoot = Split-Path $MyScriptRoot
$SavedErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = "Stop"
Set-Location $ProjectRoot
$workspacePath = (Get-Location).Path.Replace('\\', '/') + '/app-laravel'
$workspaceMount = "${workspacePath}:/var/www/html"

try {
    Remove-Item -Path "app-laravel/bootstrap/cache/*.php" -Force -ErrorAction SilentlyContinue

    if ($Fix -eq 'all' -or $Fix -eq 'lint-fix') {
        docker compose run --rm --no-deps -v "$workspaceMount" app vendor/bin/pint
        if ($LASTEXITCODE -ne 0) {
            throw "Pint fix run failed."
        }
    }

    if ($Fix -eq 'all' -or $Fix -eq 'dependencies-fix') {
        docker compose run --rm --no-deps -v "$workspaceMount" app composer update --no-scripts --with-dependencies --no-interaction --no-progress
        if ($LASTEXITCODE -ne 0) {
            throw "Composer dependencies fix failed."
        }
    }
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    $ErrorActionPreference = $SavedErrorActionPreference
}