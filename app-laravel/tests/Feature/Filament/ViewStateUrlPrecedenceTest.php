<?php

use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Support\UserViewStateStore;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
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

it('applies the default open + critical/high filter on a first visit to the alerts list', function () {
    $user = precedenceReader();

    $visible = SecurityEvent::factory()->create(['state' => 'open', 'severity' => EventSeverity::Critical]);
    $hiddenByState = SecurityEvent::factory()->create(['state' => 'resolved', 'severity' => EventSeverity::Critical]);
    $hiddenBySeverity = SecurityEvent::factory()->create(['state' => 'open', 'severity' => EventSeverity::Low]);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hiddenByState, $hiddenBySeverity]);
});

it('applies the default open + critical/high filter on a first visit to the local findings list', function () {
    $user = precedenceReader();
    $container = SecurityContainer::factory()->create();

    $visible = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'open high', 'file_path' => 'a',
        'status' => EventState::Open, 'severity' => 'HIGH',
    ]);
    $hiddenByStatus = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'resolved high', 'file_path' => 'b',
        'status' => EventState::Resolved, 'severity' => 'HIGH',
    ]);
    $hiddenBySeverity = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r3', 'title' => 'open low', 'file_path' => 'c',
        'status' => EventState::Open, 'severity' => 'LOW',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hiddenByStatus, $hiddenBySeverity]);
});

it('remembers an explicitly cleared alerts filter instead of re-applying the default', function () {
    $user = precedenceReader();

    // The user previously cleared every filter; that intentional empty state is
    // saved and must be honoured rather than falling back to the default.
    app(UserViewStateStore::class)->save($user->id, 'security-events:list', [
        'filters' => [],
        'search' => null,
        'sort' => null,
    ]);

    $lowResolved = SecurityEvent::factory()->create(['state' => 'resolved', 'severity' => EventSeverity::Low]);

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->assertCanSeeTableRecords([$lowResolved]);
});

it('persists an emptied alerts filter so the cleared state survives navigation', function () {
    $user = precedenceReader();

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->set('tableFilters.severity.values', [])
        ->set('tableFilters.state.values', [])
        ->assertOk();

    $state = app(UserViewStateStore::class)->load($user->id, 'security-events:list');

    expect($state['filters']['severity']['values'])->toBe([])
        ->and($state['filters']['state']['values'])->toBe([]);
});

it('remembers removing a single filter from the active-filters bar', function () {
    $user = precedenceReader();

    // The default seeds severity = [critical, high]; removing the indicator
    // (no Apply step) must be persisted, not reappear on the next visit.
    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->call('removeTableFilter', 'severity')
        ->assertOk();

    $state = app(UserViewStateStore::class)->load($user->id, 'security-events:list');

    expect($state['filters']['severity']['values'] ?? [])->toBe([]);
});

it('persists and restores the alerts list sort column', function () {
    $user = precedenceReader();

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->sortTable('first_seen_at', 'asc');

    $state = app(UserViewStateStore::class)->load($user->id, 'security-events:list');
    expect($state['sort'])->toBe('first_seen_at:asc');

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->assertSet('tableSort', 'first_seen_at:asc');
});

it('resets the whole alerts view back to defaults and remembers the reset', function () {
    $user = precedenceReader();

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->set('tableFilters.state.values', ['resolved'])
        ->set('tableFilters.severity.values', ['low'])
        ->sortTable('first_seen_at', 'asc')
        ->callAction('resetView')
        ->assertSet('tableFilters.state.values', ['open'])
        ->assertSet('tableFilters.severity.values', ['critical', 'high'])
        ->assertSet('tableSort', null);

    $state = app(UserViewStateStore::class)->load($user->id, 'security-events:list');
    expect($state['filters']['state']['values'])->toBe(['open'])
        ->and($state['filters']['severity']['values'])->toBe(['critical', 'high'])
        ->and($state['sort'])->toBeNull();
});
