<?php

use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ListRepositoryCollectionRuns;
use App\Models\RepositoryCollectionRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function repositoryCollectionRunAdmin(): User
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
    $admin = repositoryCollectionRunAdmin();
    $this->actingAs($admin);
    expect(RepositoryCollectionRunResource::canViewAny())->toBeTrue();

    $reader = User::factory()->create();
    $reader->syncRoles(['Reader']);
    $this->actingAs($reader);
    expect(RepositoryCollectionRunResource::canViewAny())->toBeFalse();
});

it('lists repository collection runs for an admin.queue user', function () {
    $admin = repositoryCollectionRunAdmin();

    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'batch_id' => 'batch-uuid',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 3, 'repositories_failed' => 0],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertSee('azdo-repos');
});

it('filters repository collection runs by source control and status', function () {
    $admin = repositoryCollectionRunAdmin();

    $success = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $failure = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinutes(2),
        'status' => 'failure',
        'counts_json' => [],
        'error_message' => 'boom',
    ]);

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->filterTable('status', 'success')
        ->assertCanSeeTableRecords([$success])
        ->assertCanNotSeeTableRecords([$failure]);
});

it('renders the repository collection run view page with its counts', function () {
    $admin = repositoryCollectionRunAdmin();

    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'batch_id' => 'batch-uuid',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 2, 'repositories_failed' => 0],
        'error_message' => null,
    ]);

    $this->actingAs($admin)
        ->get(RepositoryCollectionRunResource::getUrl('view', ['record' => $run]))
        ->assertOk()
        ->assertSee('azdo-repos')
        ->assertSeeText('repositories_considered');
});

it('formats counts as completed / considered with a failed tally', function () {
    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 3, 'repositories_completed' => 2, 'repositories_failed' => 1],
    ]);

    expect(RepositoryCollectionRunResource::formatCounts($run))->toBe('2 / 3 · 1 failed');
});

it('formats counts as zeroes when counts_json is empty', function () {
    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'running',
        'counts_json' => [],
    ]);

    expect(RepositoryCollectionRunResource::formatCounts($run))->toBe('0 / 0 · 0 failed');
});

it('builds a failures URL pre-filtered to the run and the repository-collection channel', function () {
    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'partial',
        'counts_json' => [],
    ]);

    $url = RepositoryCollectionRunResource::failuresUrl($run);

    expect($url)->toContain('tableFilters%5Bchannel%5D%5Bvalue%5D=repository-collection')
        ->and($url)->toContain("tableFilters%5Brun%5D%5Bvalue%5D={$run->id}");
});

it('shows the View failures action only when the run has failures', function () {
    $admin = repositoryCollectionRunAdmin();

    $clean = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 2, 'repositories_failed' => 0],
    ]);

    $this->actingAs($admin)
        ->get(RepositoryCollectionRunResource::getUrl('view', ['record' => $clean]))
        ->assertOk()
        ->assertDontSee('View failures');

    $partial = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'partial',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 2, 'repositories_failed' => 1],
    ]);

    $this->actingAs($admin)
        ->get(RepositoryCollectionRunResource::getUrl('view', ['record' => $partial]))
        ->assertOk()
        ->assertSee('View failures');
});

it('denies the view page to a user without admin.queue', function () {
    $reader = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $reader->syncRoles(['Reader']);

    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $this->actingAs($reader)
        ->get(RepositoryCollectionRunResource::getUrl('view', ['record' => $run]))
        ->assertForbidden();
});
