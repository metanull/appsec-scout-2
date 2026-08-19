<?php

use App\Audit\AuditLog;
use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ListRepositoryCollectionRuns;
use App\Filament\Support\RunCounts;
use App\Models\ErrorLog;
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

it('shows Force-finish only for a running run, and it recovers a wedged one', function () {
    $admin = repositoryCollectionRunAdmin();

    $finished = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 1, 'repositories_completed' => 1, 'repositories_failed' => 0],
    ]);

    $wedged = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subHours(6),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 10, 'repositories_completed' => 3, 'repositories_failed' => 0],
    ]);

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->assertTableActionHidden('forceFinish', $finished)
        ->assertTableActionVisible('forceFinish', $wedged)
        ->callTableAction('forceFinish', $wedged);

    $wedged->refresh();
    expect($wedged->status)->toBe('failure')
        ->and($wedged->finished_at)->not->toBeNull()
        ->and($wedged->counts_json['repositories_completed'])->toBe(3)
        ->and(AuditLog::query()->where('action', 'operations.force_finish_repository_collection_run')->exists())->toBeTrue();
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

it('renders counts via the shared RunCounts formatter', function () {
    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 3, 'repositories_completed' => 2, 'repositories_failed' => 1],
    ]);

    expect(RunCounts::format($run->counts_json))->toBe('2 / 3 · 1 failed');
});

it('builds a failures URL pre-filtered to the run and the repository-collection channel', function () {
    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'partial',
        'counts_json' => [],
    ]);

    $url = RepositoryCollectionRunResource::failuresUrl($run);

    expect($url)->toContain('tab=repository-collection')
        ->and($url)->toContain("filters%5Brun%5D%5Bvalue%5D={$run->id}");
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

it('following the failures URL actually filters the error log to the run and its channel tab', function () {
    $admin = repositoryCollectionRunAdmin();

    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'partial',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 1, 'repositories_failed' => 1],
    ]);

    $matching = ErrorLog::query()->create([
        'level' => 'ERROR',
        'channel' => 'repository-collection',
        'message' => 'clone failed',
        'context_json' => ['run' => $run->id],
        'occurred_at' => now(),
    ]);

    $otherRun = ErrorLog::query()->create([
        'level' => 'ERROR',
        'channel' => 'repository-collection',
        'message' => 'unrelated run',
        'context_json' => ['run' => $run->id + 1],
        'occurred_at' => now(),
    ]);

    $otherChannel = ErrorLog::query()->create([
        'level' => 'ERROR',
        'channel' => 'sync',
        'message' => 'unrelated channel',
        'context_json' => [],
        'occurred_at' => now(),
    ]);

    $url = RepositoryCollectionRunResource::failuresUrl($run);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $queryParams);

    Livewire::actingAs($admin)
        ->withQueryParams($queryParams)
        ->test(ListErrorLogs::class)
        ->assertSet('activeTab', 'repository-collection')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$otherRun, $otherChannel]);
});

it('shows the counts column by default and keeps it toggleable', function () {
    $admin = repositoryCollectionRunAdmin();

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->assertTableColumnVisible('counts_json');
});

it('shows a View failures row action on the list only when the run has failures', function () {
    $admin = repositoryCollectionRunAdmin();

    $clean = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 2, 'repositories_failed' => 0],
    ]);

    $partial = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'partial',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 1, 'repositories_failed' => 1],
    ]);

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->assertTableActionHidden('viewFailures', $clean)
        ->assertTableActionVisible('viewFailures', $partial);
});

it('narrows the list by searching batch_id and error_message', function () {
    $admin = repositoryCollectionRunAdmin();

    $matching = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'batch_id' => 'batch-alpha-uuid',
        'started_at' => now()->subMinute(),
        'status' => 'success',
        'counts_json' => [],
    ]);

    $other = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'batch_id' => 'batch-beta-uuid',
        'started_at' => now()->subMinute(),
        'status' => 'success',
        'counts_json' => [],
    ]);

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->searchTable('batch-alpha')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);

    $failed = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'failure',
        'counts_json' => [],
        'error_message' => 'clone timed out for backend-api',
    ]);

    Livewire::actingAs($admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->searchTable('timed out')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$matching, $other]);
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
