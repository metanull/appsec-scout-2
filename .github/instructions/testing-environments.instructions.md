---
name: 'Testing Environments'
description: 'Use when modifying local verification scripts, workflow checks, or test environment files so local Docker flows and CI workflow flows stay separate and consistent.'
applyTo: 'scripts/**/*.ps1,scripts/**/*.sh,.github/workflows/**/*.yml,app-laravel/.env*,app-laravel/phpunit*.xml,docker-compose.yml,docker/**/*.sql'
---

# Testing Environments

This repository has two distinct verification environments. Keep them separate.

## Local Verification (Developer Machine)

- Local verification runs through Docker Compose via scripts such as `scripts/invoke-check.ps1` and `scripts/invoke-check.sh`.
- Local scripts are expected to run Pint, PHPStan, and Pest in containers.
- Local scripts can validate both paths: SQLite in-memory and MySQL test database.
- Local scripts must source test configuration from `.env.testing` (generated from `.env.testing.example` when missing).
- Do not hardcode test database credentials or run manual SQL DDL in scripts.
- Use Laravel/Artisan commands (for example `php artisan migrate:fresh --force`) for schema setup.

## CI Verification (GitHub Actions)

- CI runs in workflow jobs under `.github/workflows/` and does not rely on Docker for app checks.
- CI environment values come from workflow YAML `env` and workflow steps.
- Do not assume `.env.testing` is available or used in CI unless workflow explicitly wires it.
- Keep CI SQLite in-memory checks configured in workflow env and/or PHPUnit config.
- Do not change local Docker script behavior in ways that silently alter CI behavior.

## Change Safety

- When touching scripts or workflow files, verify both flows still make sense.
- If a task needs a policy change affecting local vs CI behavior, ask the user before changing both flows.
