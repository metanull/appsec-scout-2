<?php

use App\Credentials\Credential;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Pages\SystemCredentialsPage;
use App\Models\User;
use App\Sources\Registry;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Fakes\FakeSource;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('saves and masks personal integration credentials on the profile page', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('values.fake_tracker_token', 'user-token')
        ->set('descriptions.fake-tracker', 'Personal tracker token')
        ->call('saveIntegration', 'fake-tracker')
        ->assertSee('••••••••');

    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->where('owner_user_id', $user->id)->exists())->toBeTrue();
});

it('tests personal integration credentials and updates the last tested state', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Credential::query()->create([
        'integration_key' => 'fake-tracker.token',
        'owner_user_id' => $user->id,
        'value' => 'user-token',
    ]);

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->call('testIntegration', 'fake-tracker')
        ->assertSee('Connection test succeeded.');

    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->where('owner_user_id', $user->id)->first()?->last_tested_ok)->toBeTrue();
});

it('saves system credentials from the admin page', function () {
    bindFakeCredentialIntegrations();

    $admin = enrolledUser();
    $admin->syncRoles(['Admin']);

    Livewire::actingAs($admin)
        ->test(SystemCredentialsPage::class)
        ->set('values.fake_tracker_token', 'system-token')
        ->set('descriptions.fake-tracker', 'System tracker token')
        ->call('saveIntegration', 'fake-tracker');

    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->whereNull('owner_user_id')->exists())->toBeTrue();
});

function bindFakeCredentialIntegrations(): void
{
    config([
        'integration_settings.fake.enabled' => true,
        'integration_settings.fake-tracker.enabled' => true,
    ]);

    app()->bind('appsec-scout.source.fake', fn () => new FakeSource);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app()->bind('appsec-scout.tracker.fake', fn () => new FakeTracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(Registry::class);
    app()->forgetInstance(App\Trackers\Registry::class);
}

function enrolledUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
