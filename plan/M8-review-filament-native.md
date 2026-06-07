**Summary**  
- I inspected the Blade files in `resources/views` and classified each file as either: (A) required (public UI / component) or (B) redundant for a Filament-first app and removable by switching to Filament-native primitives.

**Findings**
- **welcome.blade.php**: Required — public landing page, not part of the Filament admin UI (keep as Blade).
- **login.blade.php**: Required — public sign-in page uses a Blade component for non-admin auth (keep).
- **auth.blade.php**: Required — Blade component used by the login page (keep).
- **auth.blade.php**: Unused duplicate — I found no references to this layout; safe to remove after a final grep check.
- **integration-credentials-page.blade.php**: Redundant — only wraps `{{ $this->form }}`. Filament Page classes that implement HasForms already render forms via Filament primitives; remove this blade and drop the `protected string $view = '...integration-credentials-page'` lines from:
  - SystemCredentialsPage.php  
  - ProfileIntegrationsPage.php
- **integration-settings-page.blade.php**: Redundant — only wraps `{{ $this->table }}`. Remove file and drop the `protected string $view = '...integration-settings-page'` line from:
  - IntegrationSettingsPage.php
- **pending-sync-page.blade.php**: Redundant — only wraps `{{ $this->table }}`. Remove file and drop the `protected string $view = '...pending-sync-page'` line from:
  - PendingSyncPage.php
- **operations-page.blade.php**: Unused / redundant — file contains `{{ $this->table }}` but the OperationsPage class does not set `$view` (so the file appears leftover). Safe to delete after confirming no references.
- **view-audit-log.blade.php**: Redundant custom view — the resource already defines the infolist in `AuditLogResource::infolist()` (includes the payload entry). You can remove this view and rely on the Filament infolist; if you prefer the exact `pre` styling, port that to the resource infolist entry (TextEntry with `fontFamily('mono')`/`formatStateUsing()` is already present).

**Migration (how to remove each non-required view — short)**  
- 1) Confirm no references (run a repo grep):  
```bash
git grep "filament.pages.integration-credentials-page" || true
git grep "filament.pages.integration-settings-page" || true
git grep "filament.pages.pending-sync-page" || true
git grep "filament.resources.audit-log-resource.pages.view-audit-log" || true
git grep "auth/layouts/auth.blade.php" || true
```
- 2) For each Filament page that sets a custom view, remove the `protected string $view = '...';` line from the relevant Page class (examples above). The Page will then use Filament's default page template which automatically renders `$this->form` / `$this->table` / widgets.
- 3) Delete the now-unreferenced Blade files under `resources/views/filament/...`.
- 4) For the audit-log resource view: prefer using `AuditLogResource::infolist()` (already present). If you need exact `pre` formatting, convert the payload entry to a `TextEntry` with `fontFamily('mono')` and `formatStateUsing()` (or `html()` if you need markup).
- 5) Run formatting/static checks and tests:
```bash
# formatting + static checks (use your local dev flow / docker)
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/pint
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/phpstan analyse
APP_BUILD_TARGET=dev docker compose run --rm app vendor/bin/pest
```
- 6) Manually verify the Filament pages in the UI (forms/tables/widgets) after changes.

**Checklist before deleting files**
- Run the grep commands above to ensure no references remain.
- Remove `protected string $view = '...'` lines from Page classes that referenced the blade.
- Commit the Page edits and run the test pipeline locally.
- Delete the Blade files and run the tests again.
- Spot-check the admin pages in the browser to confirm forms/tables render as expected.

Do you want me to apply these changes now (remove the redundant blades + update Page classes and run formatting/tests)? I can make the edits and open a PR.