---
name: 'Database Portability'
description: 'Use when writing or modifying Laravel queries, Eloquent scopes, migrations, or tests that must support MySQL in the app and SQLite in tests.'
applyTo: 'app-laravel/app/**/*.php,app-laravel/database/**/*.php,app-laravel/tests/**/*.php'
---

# Database Portability

These rules apply to code that runs against both MySQL and SQLite in this repository.

- Prefer Eloquent, query builder methods, casts, relationships, scopes, and Schema builder APIs over raw SQL.
- Do not introduce database-driver-specific SQL functions or index types in application queries, scopes, or tests unless the user explicitly approves that tradeoff.
- Avoid raw SQL such as `MATCH ... AGAINST`, `JSON_EXTRACT`, `JSON_UNQUOTE`, MySQL-specific casts, or other vendor-only expressions when a framework-level portable alternative exists.
- Keep search and filter behavior portable across MySQL and SQLite. Prefer `LIKE`-based matching and regular columns over vendor-specific fulltext or JSON expressions.
- When schema changes must remove or inspect indexes, use Laravel Schema builder capabilities such as `hasIndex`, `whenTableHasIndex`, and `whenTableDoesntHaveIndex` instead of raw SQL.
- Production remains MySQL or MariaDB. SQLite support in this repository is for tests and portability verification, not as a production fallback.