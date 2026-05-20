---
name: 'Laravel Filament Compliance'
description: 'Use when writing or modifying Laravel 13, Filament 5, Fortify, Spatie Permission, Eloquent, migrations, seeders, queues, jobs, policies, Blade, Pest, PHPStan, or Pint code in app-laravel. Enforces framework-first implementation, security delegation, fail-fast behavior, and repo quality gates.'
applyTo: 'app-laravel/app/**/*.php,app-laravel/bootstrap/**/*.php,app-laravel/config/**/*.php,app-laravel/database/**/*.php,app-laravel/resources/**/*.blade.php,app-laravel/routes/**/*.php,app-laravel/tests/**/*.php'
---

# Laravel 13 And Filament 5 Compliance

These instructions are hard project rules for `app-laravel/`.

## Framework First

- Prefer Laravel 13, Filament 5, Fortify, Spatie Laravel Permission, Eloquent, queues, jobs, events, validation, policies, form requests, casts, notifications, and config APIs before custom code.
- Treat Filament as the primary application UI, not a secondary admin panel. Build operator workflows as Filament resources, pages, actions, tables, forms, and widgets where they fit.
- All valid authenticated users must be able to reach the Filament application shell after satisfying authentication and mandatory 2FA. Do not use Filament panel access as a substitute for role or feature authorization.
- Keep the Filament panel mounted at `/` through `App\Providers\Filament\AppSecScoutPanelProvider` unless a story explicitly changes the product route model.
- Use vendor-supported extension points instead of overriding internals or duplicating framework behavior.
- Do not introduce new packages unless the dependency is necessary, maintained, non-vulnerable, and explicitly approved by the user.

## Security And Authorization

- Never self-implement authentication, authorization, sessions, password handling, CSRF protection, encryption, rate limiting, or two-factor flows.
- Use Fortify for authentication flows and mandatory TOTP 2FA.
- Use Spatie Permission for roles and permissions. Preserve the cumulative role model: Reader, Triage, Plan, Sync, Admin.
- Gate every protected action through Laravel policies, Spatie permissions, Filament resource/page/action authorization hooks, middleware, or Laravel authorization APIs.
- Prefer Filament and Laravel policy best practices for UI authorization: resources, pages, relation managers, table actions, bulk actions, form actions, navigation visibility, and data queries must enforce permissions at their own boundary.
- `User::canAccessPanel()` may only decide whether a legitimate authenticated account can enter the Filament application shell. It must not encode Reader/Triage/Plan/Sync/Admin feature access, deny users merely because they lack a role, or replace policies and permissions. Use this hook only for whole-account concerns such as disabled/suspended accounts when that behavior is explicitly required and tested.
- Mandatory 2FA must be enforced before users can interact with protected Filament UI. Do not bypass Fortify or replace it with custom security logic.
- Store PATs and other secrets through the approved encrypted Laravel model casts and credential flows. Never log secrets or expose them in UI text, exceptions, tests, or seed data.

## Reliability Rules

- Fail fast. Do not swallow, hide, disguise, or silently downgrade errors.
- All application errors must be logged and surfaced to the user in a clear, actionable way.
- Do not add fallback mechanisms, placeholder code, temporary code, simulation, or degraded behavior without explicit user approval.
- Operators edit only local database state. Upstream sources are changed only when a Sync operator explicitly triggers propagation.
- All write actions must produce an audit record with timestamp and actor identity.

## Application Code

- Keep classes and methods single-purpose, small, and testable.
- Prefer constructor injection, container bindings, config files, typed value objects, casts, and service classes over facades hidden inside business logic.
- Use Eloquent relationships, scopes, casts, accessors, mutators, resources, and query builders instead of raw SQL unless a concrete performance or correctness need is documented.
- Use structured parsers, serializers, and framework helpers for parsing or transformation. Do not use regular expressions for parsing data formats.
- Use Laravel filesystem, HTTP, cache, queue, process-safe framework APIs, and Symfony Process where explicitly planned. Never execute shell strings from application code.
- Keep outbound HTTP behavior centralized through configured Guzzle/Laravel HTTP clients, including proxy and SSL settings from `config/proxy.php` when relevant.

## Database And Queues

- Model database shape with migrations, factories, and seeders. Do not rely on manual database patches for normal features.
- Write reversible migrations where Laravel supports it, and preserve MySQL 8 compatibility.
- Use Redis-backed queues and Laravel scheduled jobs according to the Docker Compose runtime model.
- Do not introduce SQLite behavior as a production fallback. Testing may use the configured test database only when the existing test configuration requires it.

## Tests And Quality Gates

- Add or update Pest tests for every new feature and bug fix.
- Test business behavior and project rules, not Laravel, Filament, Pest, or third-party internals.
- Use realistic test data based on real source or tracker shapes when integrating with AzDO, ASoC, Detectify, Defender, Jira, or GitHub.
- Do not skip tests or mark incomplete tests as a substitute for implementation.
- Before reporting code work complete, run the relevant narrow test plus the required Docker-based verification set from the repository root:

```powershell
$env:APP_BUILD_TARGET = 'dev'
docker compose build app
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose run --rm app vendor/bin/pest --no-coverage
Remove-Item Env:\APP_BUILD_TARGET
```

- Do not require host PHP, Composer, Node.js, Java, Trivy, or BFG for app development or verification. If a required tool is missing, fix the Docker image or Compose configuration.

## Style

- Follow Laravel Pint with the project `pint.json` rules.
- Use explicit, typed PHP signatures and PHPDoc only where PHPStan, generics, array shapes, or framework magic need it.
- Add comments only for non-obvious logic. Do not add comments explaining the user request or summarizing what changed.
- Keep generated UI text professional, operational, and role-oriented for security triage workflows.
