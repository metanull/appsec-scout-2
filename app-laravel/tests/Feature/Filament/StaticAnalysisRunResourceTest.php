<?php

use App\Audit\AuditLog;
use App\Filament\Resources\StaticAnalysisRunResource;
use App\Filament\Resources\StaticAnalysisRunResource\Pages\ListStaticAnalysisRuns;
use App\Models\StaticAnalysisRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function staticAnalysisRunAdmin(): User
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
    $admin = staticAnalysisRunAdmin();
    $this->actingAs($admin);
    expect(StaticAnalysisRunResource::canViewAny())->toBeTrue();

    $reader = User::factory()->create();
    $reader->syncRoles(['Reader']);
    $this->actingAs($reader);
    expect(StaticAnalysisRunResource::canViewAny())->toBeFalse();
});

it('lists static analysis runs for an admin.queue user', function () {
    $admin = staticAnalysisRunAdmin();

    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'batch_id' => 'batch-uuid',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 3, 'repositories_failed' => 0],
        'error_message' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListStaticAnalysisRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertSee('azdo-repos');
});

it('filters static analysis runs by source control and status', function () {
    $admin = staticAnalysisRunAdmin();

    $success = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $failure = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinutes(2),
        'status' => 'failure',
        'counts_json' => [],
        'error_message' => 'boom',
    ]);

    Livewire::actingAs($admin)
        ->test(ListStaticAnalysisRuns::class)
        ->filterTable('status', 'success')
        ->assertCanSeeTableRecords([$success])
        ->assertCanNotSeeTableRecords([$failure]);
});

it('shows Force-finish only for a running run, and it recovers a wedged one', function () {
    $admin = staticAnalysisRunAdmin();

    $finished = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 1, 'repositories_completed' => 1, 'repositories_failed' => 0],
    ]);

    $wedged = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subHours(6),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 10, 'repositories_completed' => 3, 'repositories_failed' => 0],
    ]);

    Livewire::actingAs($admin)
        ->test(ListStaticAnalysisRuns::class)
        ->assertTableActionHidden('forceFinish', $finished)
        ->assertTableActionVisible('forceFinish', $wedged)
        ->callTableAction('forceFinish', $wedged);

    $wedged->refresh();
    expect($wedged->status)->toBe('failure')
        ->and($wedged->finished_at)->not->toBeNull()
        ->and($wedged->counts_json['repositories_completed'])->toBe(3)
        ->and(AuditLog::query()->where('action', 'operations.force_finish_static_analysis_run')->exists())->toBeTrue();
});

it('renders the static analysis run view page with its counts', function () {
    $admin = staticAnalysisRunAdmin();

    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'batch_id' => 'batch-uuid',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 2, 'repositories_failed' => 0],
        'error_message' => null,
    ]);

    $this->actingAs($admin)
        ->get(StaticAnalysisRunResource::getUrl('view', ['record' => $run]))
        ->assertOk()
        ->assertSee('azdo-repos')
        ->assertSeeText('repositories_considered');
});

it('formats counts as completed / considered with a failed tally', function () {
    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 3, 'repositories_completed' => 2, 'repositories_failed' => 1],
    ]);

    expect(StaticAnalysisRunResource::formatCounts($run))->toBe('2 / 3 · 1 failed');
});

it('formats counts as zeroes when counts_json is empty', function () {
    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'running',
        'counts_json' => [],
    ]);

    expect(StaticAnalysisRunResource::formatCounts($run))->toBe('0 / 0 · 0 failed');
});

it('builds a failures URL pre-filtered to the run and the static-analysis channel', function () {
    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'status' => 'partial',
        'counts_json' => [],
    ]);

    $url = StaticAnalysisRunResource::failuresUrl($run);

    expect($url)->toContain('tab=static-analysis')
        ->and($url)->toContain("filters%5Brun%5D%5Bvalue%5D={$run->id}");
});

it('shows the View failures action only when the run has failures', function () {
    $admin = staticAnalysisRunAdmin();

    $clean = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 2, 'repositories_failed' => 0],
    ]);

    $this->actingAs($admin)
        ->get(StaticAnalysisRunResource::getUrl('view', ['record' => $clean]))
        ->assertOk()
        ->assertDontSee('View failures');

    $partial = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'partial',
        'counts_json' => ['repositories_considered' => 2, 'repositories_completed' => 2, 'repositories_failed' => 1],
    ]);

    $this->actingAs($admin)
        ->get(StaticAnalysisRunResource::getUrl('view', ['record' => $partial]))
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

    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
        'error_message' => null,
    ]);

    $this->actingAs($reader)
        ->get(StaticAnalysisRunResource::getUrl('view', ['record' => $run]))
        ->assertForbidden();
});
