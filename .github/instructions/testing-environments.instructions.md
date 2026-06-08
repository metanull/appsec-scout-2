---
name: 'Testing Environments'
description: 'Use when modifying local verification scripts, workflow checks, or test environment files so local Docker flows and CI workflow flows stay separate and consistent.'
applyTo: 'scripts/**/*.ps1,scripts/**/*.sh,.github/workflows/**/*.yml,app-laravel/.env*,app-laravel/phpunit*.xml,docker-compose.yml,docker/**/*.sql'
---

# Testing Environments

This repository has two distinct verification environments. Keep them separate.

## Local Verification (Developer Machine)

- Local environment relies on Docker for app checks. The containers are preconfigured, use exclusively the following scripts to interact with them, and do not require manual Docker commands:
- Initialize the app using the script `scripts/appsec-scout.ps1`, optionally passing the `-Rebuild` flag for a clean rebuild.
- Invoke checks using the script `scripts/invoke-check.ps1`, optionally passing the `-Check` parameter to specify which checks to run:
  - `-Check all` (runs all checks read-only/without fixing)
  - `-Check lint` (runs code style checks)
  - `-Check lint-fix` (runs code style checks with auto-fixing)
  - `-Check test` (runs all tests)
  - `-Check test-sqlite` (runs tests with SQLite in-memory database)
  - `-Check test-mysql` (runs tests with MySQL test database)
  - `-Check static-analysis` (runs static analysis checks)
  - `-Check smoke` (runs smoke tests, for example, checking if the app can serve a page successfully)
  - `-Check dependencies` (runs composer check for outdated dependencies)
  - `-Check dependencies-fix` (runs composer update to fix outdated dependencies)
- Use the above scripts to interact with Docker and the app. Do not run manual Docker commands or interact with the app outside of these scripts, as they are designed to maintain consistency between local and CI environments.
- Do not hardcode test database credentials or run manual SQL DDL in scripts.

## CI Verification (GitHub Actions)

- CI runs in workflow jobs under `.github/workflows/` and does not rely on Docker for app checks.
- CI environment values come from workflow YAML `env` and workflow steps.
- Do not assume `.env.testing` is available or used in CI unless workflow explicitly wires it.
- Keep CI SQLite in-memory checks configured in workflow env and/or PHPUnit config.
- Do not change local Docker script behavior in ways that silently alter CI behavior.

## Change Safety

- When touching scripts or workflow files, verify both flows still make sense.
- If a task needs a policy change affecting local vs CI behavior, ask the user before changing both flows.
