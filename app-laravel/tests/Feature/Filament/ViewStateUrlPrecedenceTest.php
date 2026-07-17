<?php

use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Support\UserViewStateStore;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function precedenceReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

it('lets a url filter win over saved view state on the alerts list', function () {
    $user = precedenceReader();

    app(UserViewStateStore::class)->save($user->id, 'security-events:list', [
        'filters' => ['severity' => ['values' => ['critical']]],
        'search' => null,
        'sort' => null,
    ]);

    // When the request carries table state, restoration is skipped, so the
    // saved "critical" filter must NOT be applied (the URL's state wins).
    Livewire::actingAs($user)
        ->withQueryParams(['tableFilters' => ['severity' => ['values' => ['high']]]])
        ->test(ListSecurityEvents::class)
        ->assertNotSet('tableFilters.severity.values', ['critical']);
});

it('restores saved view state on the alerts list when the url carries no table state', function () {
    $user = precedenceReader();

    app(UserViewStateStore::class)->save($user->id, 'security-events:list', [
        'filters' => ['severity' => ['values' => ['critical']]],
        'search' => null,
        'sort' => null,
    ]);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->assertSet('tableFilters.severity.values', ['critical']);
});

it('persists a newly chosen alerts filter after a url-filtered visit', function () {
    $user = precedenceReader();

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->set('tableFilters.severity.values', ['low'])
        ->assertOk();

    $state = app(UserViewStateStore::class)->load($user->id, 'security-events:list');

    expect($state['filters']['severity']['values'])->toBe(['low']);
});

it('lets a url filter win over saved view state on the local findings list', function () {
    $user = precedenceReader();

    app(UserViewStateStore::class)->save($user->id, 'local-findings:list', [
        'filters' => ['status' => ['values' => ['resolved']]],
        'search' => null,
        'sort' => null,
    ]);

    // When the request carries table state, restoration is skipped, so the
    // saved "resolved" filter must NOT be applied (the URL's state wins).
    Livewire::actingAs($user)
        ->withQueryParams(['tableFilters' => ['status' => ['values' => ['open']]]])
        ->test(ListLocalFindings::class)
        ->assertNotSet('tableFilters.status.values', ['resolved']);
});

it('restores saved view state on the local findings list without url table state', function () {
    $user = precedenceReader();

    app(UserViewStateStore::class)->save($user->id, 'local-findings:list', [
        'filters' => ['status' => ['values' => ['resolved']]],
        'search' => null,
        'sort' => null,
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertSet('tableFilters.status.values', ['resolved']);
});

it('persists a newly chosen local findings filter after a url-filtered visit', function () {
    $user = precedenceReader();

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->set('tableFilters.status.values', ['dismissed'])
        ->assertOk();

    $state = app(UserViewStateStore::class)->load($user->id, 'local-findings:list');

    expect($state['filters']['status']['values'])->toBe(['dismissed']);
});
