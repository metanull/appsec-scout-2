<?php

use App\Assets\DependencyTrack\DependencyTrackSystemCredentials;
use App\Audit\AuditLog;
use App\Credentials\Credential;
use App\Credentials\Vault;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Pages\SystemCredentialsPage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('shows the Dependency-Track section on the system credentials page only', function () {
    $admin = enrolledUserForDependencyTrack();
    $admin->syncRoles(['Admin']);

    Livewire::actingAs($admin)
        ->test(SystemCredentialsPage::class)
        ->assertSee('Dependency-Track');

    Livewire::actingAs($admin)
        ->test(ProfileIntegrationsPage::class)
        ->assertDontSee('Dependency-Track');
});

it('saves the Dependency-Track credentials system-scoped with an audit row', function () {
    $admin = enrolledUserForDependencyTrack();
    $admin->syncRoles(['Admin']);

    Livewire::actingAs($admin)
        ->test(SystemCredentialsPage::class)
        ->set('values.dependencytrack_baseUrl', 'http://dtrack.internal:9010')
        ->set('values.dependencytrack_apiKey', 'odt_new_key')
        ->call('saveIntegration', 'dependencytrack')
        ->assertHasNoErrors();

    expect(Credential::query()->where('integration_key', 'dependencytrack.baseUrl')->whereNull('owner_user_id')->exists())->toBeTrue()
        ->and(app(Vault::class)->get('dependencytrack.apiKey', null))->toBe('odt_new_key')
        ->and(AuditLog::query()->where('action', 'credential_change')->exists())->toBeTrue();
});

it('reports a successful connection test through the page', function () {
    $admin = enrolledUserForDependencyTrack();
    $admin->syncRoles(['Admin']);

    app(Vault::class)->set('dependencytrack.baseUrl', null, 'http://dtrack.internal:9010');
    app(Vault::class)->set('dependencytrack.apiKey', null, 'odt_valid_key');

    bindDependencyTrackProbe([new Response(200, [], '{"name":"Automation"}')]);

    Livewire::actingAs($admin)
        ->test(SystemCredentialsPage::class)
        ->call('testIntegration', 'dependencytrack')
        ->assertHasNoErrors();

    expect(Credential::query()->where('integration_key', 'dependencytrack.apiKey')->whereNull('owner_user_id')->first()?->last_tested_ok)->toBeTrue();
});

it('fails the connection test on an unauthorized response without leaking the key', function () {
    app(Vault::class)->set('dependencytrack.baseUrl', null, 'http://dtrack.internal:9010');
    app(Vault::class)->set('dependencytrack.apiKey', null, 'odt_wrong_key');

    $probe = new DependencyTrackSystemCredentials(
        app(Vault::class),
        new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401, [], 'Unauthorized')]))]),
    );

    $result = $probe->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('401')
        ->and($result->error)->not->toContain('odt_wrong_key');
});

it('fails the connection test when the API key is not configured', function () {
    $probe = new DependencyTrackSystemCredentials(app(Vault::class));

    $result = $probe->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('not configured');
});

function bindDependencyTrackProbe(array $responses): void
{
    app()->instance(DependencyTrackSystemCredentials::class, new DependencyTrackSystemCredentials(
        app(Vault::class),
        new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
    ));
}

function enrolledUserForDependencyTrack(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
