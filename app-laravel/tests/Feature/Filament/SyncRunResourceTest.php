<?php

use App\Filament\Resources\SyncRunResource;
use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Models\SyncRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function syncRunAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

it('grants access to an admin.queue user and denies a reader', function () {
    $admin = syncRunAdmin();
    $this->actingAs($admin);
    expect(SyncRunResource::canViewAny())->toBeTrue();

    $reader = User::factory()->create();
    $reader->syncRoles(['Reader']);
    $this->actingAs($reader);
    expect(SyncRunResource::canViewAny())->toBeFalse();
});

it('lists sync runs for an admin.queue user', function () {
    $admin = syncRunAdmin();

    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['events_created' => 3, 'events_updated' => 1],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListSyncRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertSee('azdo');
});

it('searches and filters sync runs by source and status', function () {
    $admin = syncRunAdmin();

    $azdo = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $asoc = SyncRun::query()->create([
        'source_id' => 'asoc',
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinutes(2),
        'status' => 'failure',
        'counts_json' => [],
        'error_message' => 'boom',
    ]);

    Livewire::actingAs($admin)
        ->test(ListSyncRuns::class)
        ->filterTable('source_id', 'azdo')
        ->assertCanSeeTableRecords([$azdo])
        ->assertCanNotSeeTableRecords([$asoc])
        ->removeTableFilters()
        ->filterTable('status', 'failure')
        ->assertCanSeeTableRecords([$asoc])
        ->assertCanNotSeeTableRecords([$azdo]);
});

it('sorts sync runs by started_at', function () {
    $admin = syncRunAdmin();

    $older = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $newer = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListSyncRuns::class)
        ->sortTable('started_at', 'asc')
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

it('renders the sync run view page with its counts', function () {
    $admin = syncRunAdmin();

    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['events_created' => 2, 'events_updated' => 0],
        'error_message' => null,
    ]);

    $this->actingAs($admin)
        ->get(SyncRunResource::getUrl('view', ['record' => $run]))
        ->assertOk()
        ->assertSee('azdo')
        ->assertSee('events_created');
});

it('denies the view page to a user without admin.queue', function () {
    $reader = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $reader->syncRoles(['Reader']);

    $run = SyncRun::query()->create([
        'source_id' => 'azdo',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $this->actingAs($reader)
        ->get(SyncRunResource::getUrl('view', ['record' => $run]))
        ->assertForbidden();
});

it('filters sync runs by a started_at date range', function () {
    $admin = syncRunAdmin();

    $early = SyncRun::query()->create([
        'source_id' => 'azdo', 'started_at' => '2026-01-05 00:00:00', 'finished_at' => '2026-01-05 00:01:00',
        'status' => 'success', 'counts_json' => [], 'error_message' => null,
    ]);
    $late = SyncRun::query()->create([
        'source_id' => 'azdo', 'started_at' => '2026-01-25 00:00:00', 'finished_at' => '2026-01-25 00:01:00',
        'status' => 'success', 'counts_json' => [], 'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListSyncRuns::class)
        ->filterTable('started_at_from', ['started_at_from' => '2026-01-10'])
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$early])
        ->removeTableFilter('started_at_from')
        ->filterTable('started_at_until', ['started_at_until' => '2026-01-10'])
        ->assertCanSeeTableRecords([$early])
        ->assertCanNotSeeTableRecords([$late]);
});
