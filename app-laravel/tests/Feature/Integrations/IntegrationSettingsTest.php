<?php

use App\Audit\AuditLog;
use App\Credentials\Credential;
use App\Filament\Pages\IntegrationSettingsPage;
use App\Integrations\DispatchDueIntegrations;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use App\Sync\CredentialResolver;
use App\Trackers\Registry as TrackerRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Fakes\FakeSource;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    bindFakeIntegrationsForSettings();
});

it('exposes all integrations while enabled filters use database-backed settings', function () {
    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => false, 'fetch_interval_minutes' => 30],
    );
    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'tracker', 'integration_id' => 'fake-tracker'],
        ['enabled' => true, 'fetch_interval_minutes' => 30],
    );

    $sources = app(SourceRegistry::class);
    $trackers = app(TrackerRegistry::class);

    expect(collect($sources->all())->map->id()->all())->toContain('fake')
        ->and(collect($sources->enabled())->map->id()->all())->not->toContain('fake')
        ->and(collect($trackers->all())->map->id()->all())->toContain('fake-tracker')
        ->and(collect($trackers->enabled())->map->id()->all())->toContain('fake-tracker');
});

it('dispatches only due integrations from database-backed settings', function () {
    Cache::flush();

    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        [
            'enabled' => true,
            'fetch_interval_minutes' => 5,
            'last_synced_at' => now()->subMinutes(10),
        ],
    );
    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'tracker', 'integration_id' => 'fake-tracker'],
        [
            'enabled' => true,
            'fetch_interval_minutes' => 30,
            'last_synced_at' => now()->subMinutes(5),
        ],
    );

    $count = app(DispatchDueIntegrations::class)->dispatchDue();

    expect($count)->toBe(1);
});

it('uses system credentials for background credential resolution', function () {
    $serviceUser = User::factory()->create();

    Credential::query()->create(['integration_key' => 'fake.apiKey', 'owner_user_id' => null, 'value' => 'system-token']);
    Credential::query()->create(['integration_key' => 'fake.apiKey', 'owner_user_id' => $serviceUser->id, 'value' => 'service-token']);

    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => true, 'fetch_interval_minutes' => 30, 'service_user_id' => $serviceUser->id],
    );

    expect(app(CredentialResolver::class)->resolve('fake.apiKey')?->value)->toBe('system-token');
});

it('saves integration settings and records an audit row', function () {
    $admin = enrolledAdmin();
    $serviceUser = User::factory()->create(['name' => 'Service User']);
    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => false, 'fetch_interval_minutes' => 30, 'service_user_id' => null],
    );

    Livewire::actingAs($admin)
        ->test(IntegrationSettingsPage::class)
        ->set('settings.source:fake.enabled', true)
        ->set('settings.source:fake.fetch_interval_minutes', 12)
        ->set('settings.source:fake.service_user_id', (string) $serviceUser->id)
        ->call('saveIntegration', 'source:fake');

    expect(IntegrationSetting::query()->where('integration_kind', 'source')->where('integration_id', 'fake')->first())
        ->not->toBeNull()
        ->enabled->toBeTrue()
        ->fetch_interval_minutes->toBe(12)
        ->service_user_id->toBe($serviceUser->id);

    expect(AuditLog::query()->where('action', 'integration.settings_updated')->exists())->toBeTrue();
});

it('tests a connection with system credentials and records an audit row', function () {
    $admin = enrolledAdmin();
    $serviceUser = User::factory()->create();

    Credential::query()->create([
        'integration_key' => 'fake.apiKey',
        'owner_user_id' => null,
        'value' => 'system-token',
    ]);

    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => true, 'fetch_interval_minutes' => 30, 'service_user_id' => $serviceUser->id],
    );

    $record = IntegrationSetting::query()
        ->where('integration_kind', 'source')
        ->where('integration_id', 'fake')
        ->firstOrFail();

    Livewire::actingAs($admin)
        ->test(IntegrationSettingsPage::class)
        ->callTableAction('testConnection', $record);

    expect(AuditLog::query()->where('action', 'integration.connection_tested')->exists())->toBeTrue();
});

it('summarizes oversized database sync errors for the integrations table', function () {
    $message = "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'version_control_url' at row 1 (Connection: mysql, SQL: insert into `security_events` values (...very long upstream payload...))";

    expect((new IntegrationSettingsPage)->statusMessageSummary($message))
        ->toBe('Data too long for version_control_url. See Error Logs for the full database error.');
});

it('prunes settings for integrations that are no longer registered', function () {
    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'stale-source'],
        ['enabled' => true, 'fetch_interval_minutes' => 30],
    );

    app(IntegrationSettingsRepository::class)->syncKnown('source', ['fake']);

    expect(IntegrationSetting::query()->where('integration_id', 'stale-source')->exists())->toBeFalse()
        ->and(IntegrationSetting::query()->where('integration_id', 'fake')->exists())->toBeTrue();
});

function bindFakeIntegrationsForSettings(): void
{
    config([
        'integration_settings.fake.enabled' => false,
        'integration_settings.fake-tracker.enabled' => false,
    ]);

    app()->bind('appsec-scout.source.fake', fn () => new FakeSource);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app()->bind('appsec-scout.tracker.fake', fn () => new FakeTracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(SourceRegistry::class);
    app()->forgetInstance(TrackerRegistry::class);
    app()->forgetInstance(IntegrationSettingsRepository::class);
}

function enrolledAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}
