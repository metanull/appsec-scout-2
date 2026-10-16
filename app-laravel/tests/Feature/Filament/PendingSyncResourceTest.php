<?php

use App\Credentials\Vault;
use App\Filament\Resources\PendingSyncResource;
use App\Filament\Resources\PendingSyncResource\Pages\ListPendingSync;
use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Fakes\FakeSource;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function pendingSyncUser(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Sync']);

    return $user;
}

it('renders pending sync for sync role users', function () {
    $user = pendingSyncUser();

    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => EventState::Resolved,
        'pending_severity' => EventSeverity::Low,
    ]);

    $this->actingAs($user)
        ->get('/sync/pending')
        ->assertSuccessful()
        ->assertSee('Pending Sync');
});

it('renders a row whose pending state is null without error', function () {
    $user = pendingSyncUser();

    SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => null,
        'pending_severity' => EventSeverity::Low,
    ]);

    $this->actingAs($user)
        ->get('/sync/pending')
        ->assertSuccessful();
});

it('denies access to a user without both work-items.sync and sources.push-state', function () {
    $reader = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $reader->syncRoles(['Reader']);

    expect(PendingSyncResource::canViewAny())->toBeFalse();

    $this->actingAs($reader)
        ->get('/sync/pending')
        ->assertForbidden();
});

it('only lists dirty alerts and links each row to its alert view page', function () {
    $user = pendingSyncUser();

    $dirty = SecurityEvent::factory()->create([
        'source_id' => 'azdo',
        'is_dirty' => true,
        'pending_state' => EventState::Resolved,
    ]);
    $clean = SecurityEvent::factory()->create(['source_id' => 'azdo', 'is_dirty' => false]);

    Livewire::actingAs($user)
        ->test(ListPendingSync::class)
        ->assertCanSeeTableRecords([$dirty])
        ->assertCanNotSeeTableRecords([$clean]);

    $this->actingAs($user)
        ->get('/sync/pending')
        ->assertSee(SecurityEventResource::getUrl('view', ['record' => $dirty]), false);
});

it('filters pending sync by source, and the filter round-trips through the query string', function () {
    $user = pendingSyncUser();

    $azdo = SecurityEvent::factory()->create(['source_id' => 'azdo', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);
    $asoc = SecurityEvent::factory()->create(['source_id' => 'asoc', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);

    Livewire::actingAs($user)
        ->withQueryParams(['filters' => ['source_id' => ['value' => 'azdo']]])
        ->test(ListPendingSync::class)
        ->assertSet('tableFilters.source_id.value', 'azdo')
        ->assertCanSeeTableRecords([$azdo])
        ->assertCanNotSeeTableRecords([$asoc]);
});

it('pushes the selected rows synchronously per source and reports each outcome', function () {
    Queue::fake();
    $source = new FakeSource;
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);

    $user = pendingSyncUser();
    app(Vault::class)->set('fake.apiKey', $user->id, 'operator-key');

    $pushed = SecurityEvent::factory()->create(['source_id' => 'fake', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);
    $unknown = SecurityEvent::factory()->create(['source_id' => 'gone', 'is_dirty' => true, 'pending_state' => EventState::Dismissed]);

    Livewire::actingAs($user)
        ->test(ListPendingSync::class)
        ->callTableBulkAction('pushToSource', [$pushed])
        ->assertNotified('fake: 1 pushed')
        ->callTableBulkAction('pushToSource', [$unknown])
        ->assertNotified('gone: nothing pushed');

    expect($source->pushCalls)->toBe(1)
        ->and($pushed->fresh()?->is_dirty)->toBeFalse()
        ->and($unknown->fresh()?->is_dirty)->toBeTrue();

    Queue::assertNothingPushed();
});

it('refuses a push when the user has no credential of their own for the source', function () {
    $source = new FakeSource;
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);
    app(Vault::class)->set('fake.apiKey', null, 'system-key');

    $event = SecurityEvent::factory()->create(['source_id' => 'fake', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);

    Livewire::actingAs(pendingSyncUser())
        ->test(ListPendingSync::class)
        ->callTableBulkAction('pushToSource', [$event])
        ->assertNotified('fake: nothing pushed');

    expect($source->pushCalls)->toBe(0)
        ->and($event->fresh()?->is_dirty)->toBeTrue();
});

it('discards the selected pending changes without pushing them', function () {
    $source = new FakeSource;
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);

    $stuck = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'state' => EventState::Open,
        'is_dirty' => true,
        'pending_state' => EventState::Dismissed,
        'metadata' => ['pushRetryCount' => 3, 'lastPushError' => '409 Conflict'],
    ]);
    $kept = SecurityEvent::factory()->create(['source_id' => 'fake', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);

    Livewire::actingAs(pendingSyncUser())
        ->test(ListPendingSync::class)
        ->callTableBulkAction('discardPending', [$stuck])
        ->assertNotified('Discarded 1 pending change(s)')
        ->assertCanNotSeeTableRecords([$stuck])
        ->assertCanSeeTableRecords([$kept]);

    expect($source->pushCalls)->toBe(0)
        ->and($stuck->fresh()?->is_dirty)->toBeFalse()
        ->and($stuck->fresh()?->state)->toBe(EventState::Open)
        ->and($kept->fresh()?->is_dirty)->toBeTrue();
});

it('hides the discard action from a user without alerts.edit', function () {
    $user = pendingSyncUser();
    Role::findByName('Sync')->revokePermissionTo('alerts.edit');

    $event = SecurityEvent::factory()->create(['source_id' => 'fake', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);

    Livewire::actingAs($user)
        ->test(ListPendingSync::class)
        ->assertTableBulkActionHidden('discardPending');

    expect($event->fresh()?->is_dirty)->toBeTrue();
});
