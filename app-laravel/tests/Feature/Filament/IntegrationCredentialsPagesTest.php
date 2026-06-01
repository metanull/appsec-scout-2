<?php

use App\Credentials\Credential;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Pages\SystemCredentialsPage;
use App\Models\User;
use App\Sources\Registry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeMultiFieldSource;
use Tests\Fakes\FakeSource;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('saves and shows Stored badge for personal integration secret credentials', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('values.fake_tracker_token', 'user-token')
        ->set('descriptions.fake-tracker', 'Personal tracker token')
        ->call('saveIntegration', 'fake-tracker')
        ->assertSee('Stored');

    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->where('owner_user_id', $user->id)->exists())->toBeTrue();
});

it('tests personal integration credentials and shows connected badge', function () {
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
        ->assertSee('Connected');

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

it('renders editable inputs for empty required system credentials', function () {
    bindFakeCredentialIntegrations();

    $admin = enrolledUser();
    $admin->syncRoles(['Admin']);

    Livewire::actingAs($admin)
        ->test(SystemCredentialsPage::class)
        ->assertSeeHtml('wire:model.live="values.fake_apiKey"')
        ->assertSeeHtml('wire:model.live="values.fake_tracker_token"')
        ->assertDontSee('x-filament::input');
});

it('saves all credentials for multiple integrations at once', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('values.fake_apiKey', 'source-key')
        ->set('values.fake_tracker_token', 'tracker-token')
        ->call('saveAllCredentials');

    expect(Credential::query()->where('integration_key', 'fake.apiKey')->where('owner_user_id', $user->id)->exists())->toBeTrue();
    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->where('owner_user_id', $user->id)->exists())->toBeTrue();
});

it('allows save all when one integration remains fully empty', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('values.fake_apiKey', 'source-key')
        ->call('saveAllCredentials')
        ->assertHasNoErrors();

    expect(Credential::query()->where('integration_key', 'fake.apiKey')->where('owner_user_id', $user->id)->exists())->toBeTrue();
    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->where('owner_user_id', $user->id)->exists())->toBeFalse();
});

it('rejects partial required fields within one integration', function () {
    bindFakeMultiFieldSourceIntegration();

    $user = enrolledUser();

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('values.fake_multi_username', 'operator')
        ->set('values.fake_multi_token', '')
        ->call('saveAllCredentials')
        ->assertHasErrors(['values.fake_multi_token']);

    expect(Credential::query()->where('integration_key', 'fake-multi.username')->where('owner_user_id', $user->id)->exists())->toBeFalse();
    expect(Credential::query()->where('integration_key', 'fake-multi.token')->where('owner_user_id', $user->id)->exists())->toBeFalse();
});

it('tests all configured integrations in one action', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Credential::query()->create([
        'integration_key' => 'fake.apiKey',
        'owner_user_id' => $user->id,
        'value' => 'source-key',
    ]);
    Credential::query()->create([
        'integration_key' => 'fake-tracker.token',
        'owner_user_id' => $user->id,
        'value' => 'tracker-token',
    ]);

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->call('testAllConfiguredIntegrations')
        ->assertSee('Connected');
});

it('replaces a stored secret when replace is activated', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Credential::query()->create([
        'integration_key' => 'fake-tracker.token',
        'owner_user_id' => $user->id,
        'value' => 'old-token',
    ]);

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('replace.fake_tracker_token', true)
        ->set('values.fake_tracker_token', 'new-token')
        ->call('saveIntegration', 'fake-tracker');

    expect(Credential::query()->where('integration_key', 'fake-tracker.token')->where('owner_user_id', $user->id)->first()?->value)->toBe('new-token');
});

it('requires a replacement value when replace is activated for a secret', function () {
    bindFakeCredentialIntegrations();

    $user = enrolledUser();

    Credential::query()->create([
        'integration_key' => 'fake-tracker.token',
        'owner_user_id' => $user->id,
        'value' => 'old-token',
    ]);

    Livewire::actingAs($user)
        ->test(ProfileIntegrationsPage::class)
        ->set('replace.fake_tracker_token', true)
        ->set('values.fake_tracker_token', '')
        ->call('saveIntegration', 'fake-tracker')
        ->assertHasErrors(['values.fake_tracker_token']);
});

it('renders system credentials page even when an existing credential is unreadable', function () {
    bindFakeCredentialIntegrations();

    $admin = enrolledUser();
    $admin->syncRoles(['Admin']);

    DB::table('credentials')->insert([
        'integration_key' => 'fake-tracker.token',
        'owner_user_id' => null,
        'value' => 'invalid-payload',
    ]);

    Livewire::actingAs($admin)
        ->test(SystemCredentialsPage::class)
        ->assertSeeHtml('wire:model.live="values.fake_tracker_token"');
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

function bindFakeMultiFieldSourceIntegration(): void
{
    config([
        'integration_settings.fake-multi.enabled' => true,
    ]);

    app()->bind('appsec-scout.source.fake-multi', fn () => new FakeMultiFieldSource);
    app()->tag(['appsec-scout.source.fake-multi'], 'appsec-scout.source');

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
