<?php

use App\Filament\Resources\PendingSyncResource;
use App\Filament\Resources\PendingSyncResource\Pages\ListPendingSync;
use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PushEventStatesJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

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

it('dispatches a push job per source for the selected rows', function () {
    Bus::fake();

    $user = pendingSyncUser();

    $azdoEvent = SecurityEvent::factory()->create(['source_id' => 'azdo', 'is_dirty' => true, 'pending_state' => EventState::Resolved]);
    $asocEvent = SecurityEvent::factory()->create(['source_id' => 'asoc', 'is_dirty' => true, 'pending_state' => EventState::Dismissed]);

    Livewire::actingAs($user)
        ->test(ListPendingSync::class)
        ->callTableBulkAction('pushToSource', [$azdoEvent, $asocEvent]);

    Bus::assertDispatched(PushEventStatesJob::class, fn (PushEventStatesJob $job): bool => $job->eventIds === [$azdoEvent->id]);
    Bus::assertDispatched(PushEventStatesJob::class, fn (PushEventStatesJob $job): bool => $job->eventIds === [$asocEvent->id]);
});
