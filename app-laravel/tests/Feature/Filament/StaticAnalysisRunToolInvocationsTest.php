<?php

use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ViewRepositoryCollectionRun;
use App\Filament\Resources\Shared\RelationManagers\ToolInvocationsRelationManager;
use App\Filament\Resources\StaticAnalysisRunResource;
use App\Filament\Resources\StaticAnalysisRunResource\Pages\ViewStaticAnalysisRun;
use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;
use App\Models\ToolInvocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function toolInvocationsAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

function toolInvocationsReader(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    return $user;
}

dataset('collection run resources', [
    'static analysis run' => [StaticAnalysisRun::class, ViewStaticAnalysisRun::class, StaticAnalysisRunResource::class],
    'repository collection run' => [RepositoryCollectionRun::class, ViewRepositoryCollectionRun::class, RepositoryCollectionRunResource::class],
]);

it('renders the tool invocations relation manager rows for a user with admin.queue', function (string $runModel, string $pageClass) {
    $admin = toolInvocationsAdmin();

    /** @var StaticAnalysisRun|RepositoryCollectionRun $run */
    $run = $runModel::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
    ]);

    $invocation = ToolInvocation::factory()->create([
        'run_type' => $runModel,
        'run_id' => $run->id,
        'tool' => 'opengrep',
        'outcome' => 'ran_clean',
    ]);

    Livewire::actingAs($admin)
        ->test(ToolInvocationsRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => $pageClass,
        ])
        ->assertCanSeeTableRecords([$invocation]);
})->with('collection run resources');

it('isolates skipped_no_toolchain rows via the outcome filter', function (string $runModel, string $pageClass) {
    $admin = toolInvocationsAdmin();

    /** @var StaticAnalysisRun|RepositoryCollectionRun $run */
    $run = $runModel::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
    ]);

    $skipped = ToolInvocation::factory()->create([
        'run_type' => $runModel,
        'run_id' => $run->id,
        'tool' => 'dotnet-roslynator',
        'outcome' => 'skipped_no_toolchain',
    ]);

    $ran = ToolInvocation::factory()->create([
        'run_type' => $runModel,
        'run_id' => $run->id,
        'tool' => 'opengrep',
        'outcome' => 'ran_clean',
    ]);

    Livewire::actingAs($admin)
        ->test(ToolInvocationsRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => $pageClass,
        ])
        ->filterTable('outcome', 'skipped_no_toolchain')
        ->assertCanSeeTableRecords([$skipped])
        ->assertCanNotSeeTableRecords([$ran]);
})->with('collection run resources');

it('gates the relation manager behind admin.queue like CollectionRunResource::canViewAny()', function (string $runModel, string $pageClass) {
    /** @var StaticAnalysisRun|RepositoryCollectionRun $run */
    $run = $runModel::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
    ]);

    $admin = toolInvocationsAdmin();
    $this->actingAs($admin);
    expect(ToolInvocationsRelationManager::canViewForRecord($run, $pageClass))->toBeTrue();

    $reader = toolInvocationsReader();
    $this->actingAs($reader);
    expect(ToolInvocationsRelationManager::canViewForRecord($run, $pageClass))->toBeFalse();
})->with('collection run resources');

it('shows the Tool Invocations tab on the run view page for an admin.queue user', function (string $runModel, string $pageClass, string $resourceClass) {
    $admin = toolInvocationsAdmin();

    /** @var StaticAnalysisRun|RepositoryCollectionRun $run */
    $run = $runModel::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'status' => 'success',
        'counts_json' => [],
    ]);

    $this->actingAs($admin)
        ->get($resourceClass::getUrl('view', ['record' => $run]))
        ->assertOk()
        ->assertSee('Tool Invocations');
})->with('collection run resources');
