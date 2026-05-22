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

it('prefers the configured integration service user for background credential resolution', function () {
    $serviceUser = User::factory()->create();

    Credential::query()->create(['integration_key' => 'fake.apiKey', 'owner_user_id' => null, 'value' => 'system-token']);
    Credential::query()->create(['integration_key' => 'fake.apiKey', 'owner_user_id' => $serviceUser->id, 'value' => 'service-token']);

    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => true, 'fetch_interval_minutes' => 30, 'service_user_id' => $serviceUser->id],
    );

    expect(app(CredentialResolver::class)->resolve('fake.apiKey')?->value)->toBe('service-token');
});

it('saves integration settings and records an audit row', function () {
    $admin = enrolledAdmin();
    $serviceUser = User::factory()->create(['name' => 'Service User']);

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

it('tests a connection with the selected service user credentials and records an audit row', function () {
    $admin = enrolledAdmin();
    $serviceUser = User::factory()->create();

    Credential::query()->create([
        'integration_key' => 'fake.apiKey',
        'owner_user_id' => $serviceUser->id,
        'value' => 'service-token',
    ]);

    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => true, 'fetch_interval_minutes' => 30, 'service_user_id' => $serviceUser->id],
    );

    Livewire::actingAs($admin)
        ->test(IntegrationSettingsPage::class)
        ->call('testIntegration', 'source:fake')
        ->assertSee('Connection test succeeded.');

    expect(Credential::query()->where('integration_key', 'fake.apiKey')->where('owner_user_id', $serviceUser->id)->first()?->last_tested_ok)
        ->toBeTrue()
        ->and(AuditLog::query()->where('action', 'integration.connection_tested')->exists())->toBeTrue();
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
