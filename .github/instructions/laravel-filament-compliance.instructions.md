---
name: 'Laravel Filament Compliance'
description: 'Use when writing or modifying Laravel 13, Filament 5, Fortify, Spatie Permission, Eloquent, migrations, seeders, queues, jobs, policies, Blade, Pest, PHPStan, or Pint code in app-laravel. Enforces framework-first implementation, security delegation, fail-fast behavior, and repo quality gates.'
applyTo:
  - 'app-laravel/app/**/*.php'
  - 'app-laravel/bootstrap/**/*.php'
  - 'app-laravel/config/**/*.php'
  - 'app-laravel/database/**/*.php'
  - 'app-laravel/resources/**/*.blade.php'
  - 'app-laravel/routes/**/*.php'
  - 'app-laravel/tests/**/*.php'
---

# Laravel 13 And Filament 5 Compliance

These instructions are hard project rules for `app-laravel/`.

## Framework First

- Prefer Laravel 13, Filament 5, Fortify, Spatie Laravel Permission, Eloquent, queues, jobs, events, validation, policies, form requests, casts, notifications, and config APIs before custom code.
- Treat Filament as the primary application UI, not a secondary admin panel. Build operator workflows as Filament resources, pages, actions, tables, forms, and widgets where they fit.
- Keep the Filament panel mounted at `/` through `App\Providers\Filament\AppSecScoutPanelProvider` unless a story explicitly changes the product route model.
- Use vendor-supported extension points instead of overriding internals or duplicating framework behavior.
- Do not introduce new packages unless the dependency is necessary, maintained, non-vulnerable, and explicitly approved by the user.

## Security And Authorization

- Never self-implement authentication, authorization, sessions, password handling, CSRF protection, encryption, rate limiting, or two-factor flows.
- Use Fortify for authentication flows and mandatory TOTP 2FA.
- Use Spatie Permission for roles and permissions. Preserve the cumulative role model: Reader, Triage, Plan, Sync, Admin.
- Gate every protected action through policies, permissions, Filament authorization hooks, middleware, or Laravel authorization APIs.
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
- Before reporting code work complete, run the relevant narrow test plus the required verification set from `app-laravel/`:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pest --no-coverage
```

- If local PHP or Composer is unavailable, run the documented Composer Docker-image equivalents from the repository root.

## Style

- Follow Laravel Pint with the project `pint.json` rules.
- Use explicit, typed PHP signatures and PHPDoc only where PHPStan, generics, array shapes, or framework magic need it.
- Add comments only for non-obvious logic. Do not add comments explaining the user request or summarizing what changed.
- Keep generated UI text professional, operational, and role-oriented for security triage workflows.
