<?php

use App\Audit\AuditLog;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ListRepositoryCollectionRuns;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\RepositoryCollectionRun;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\Models\User;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    Queue::fake();
    $this->admin = User::factory()->create();
    $this->admin->syncRoles(['Admin']);
});

it('queues repository collection for one Azure DevOps system', function () {
    $system = SoftwareSystem::factory()->azdo()->create();

    Livewire::actingAs($this->admin)
        ->test(ViewSoftwareSystem::class, ['record' => $system->getRouteKey()])
        ->assertActionVisible('collectRepositories')
        ->callAction('collectRepositories')
        ->assertNotified();

    Queue::assertPushed(DispatchRepositoryCollectionRunsJob::class, fn (DispatchRepositoryCollectionRunsJob $job): bool => $job->softwareSystemId === $system->id);

    expect(AuditLog::query()->where('action', 'operations.collect_repositories')->sole()->payload_json['software_system_id'])
        ->toBe($system->id);
});

it('refuses to queue a second collection while one is running', function () {
    $system = SoftwareSystem::factory()->azdo()->create();
    RepositoryCollectionRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'running', 'counts_json' => []]);

    Livewire::actingAs($this->admin)
        ->test(ViewSoftwareSystem::class, ['record' => $system->getRouteKey()])
        ->callAction('collectRepositories')
        ->assertNotified('A repository collection run is already in progress.');

    Queue::assertNothingPushed();
});

it('offers repository collection only to admins, on active Azure DevOps systems', function () {
    $sync = User::factory()->create();
    $sync->syncRoles(['Sync']);

    Livewire::actingAs($sync)
        ->test(ViewSoftwareSystem::class, ['record' => SoftwareSystem::factory()->azdo()->create()->getRouteKey()])
        ->assertActionHidden('collectRepositories');

    Livewire::actingAs($this->admin)
        ->test(ViewSoftwareSystem::class, ['record' => SoftwareSystem::factory()->create(['source_id' => 'detectify'])->getRouteKey()])
        ->assertActionHidden('collectRepositories');

    Livewire::actingAs($this->admin)
        ->test(ViewSoftwareSystem::class, ['record' => SoftwareSystem::factory()->azdo()->create(['removed_at' => now()])->getRouteKey()])
        ->assertActionHidden('collectRepositories');
});

it('shows which system a collection run covered', function () {
    $system = SoftwareSystem::factory()->azdo()->create(['name' => 'Payments Project']);
    RepositoryCollectionRun::query()->create(['source_control_id' => 'azdo-repos', 'software_system_id' => $system->id, 'started_at' => now(), 'status' => 'success', 'counts_json' => []]);
    RepositoryCollectionRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'success', 'counts_json' => []]);

    Livewire::actingAs($this->admin)
        ->test(ListRepositoryCollectionRuns::class)
        ->assertSee('Payments Project')
        ->assertSee('All systems');
});

it('queues a forced static analysis for one Azure DevOps system', function () {
    $system = SoftwareSystem::factory()->azdo()->create();

    Livewire::actingAs($this->admin)
        ->test(ViewSoftwareSystem::class, ['record' => $system->getRouteKey()])
        ->assertActionVisible('runStaticAnalysis')
        ->callAction('runStaticAnalysis', data: ['force' => true])
        ->assertNotified();

    Queue::assertPushed(DispatchStaticAnalysisRunsJob::class, fn (DispatchStaticAnalysisRunsJob $job): bool => $job->softwareSystemId === $system->id && $job->force === true);

    expect(AuditLog::query()->where('action', 'operations.run_static_analysis')->sole()->payload_json)
        ->toBe(['force' => true, 'software_system_id' => $system->id]);
});

it('refuses to queue a second static analysis while one is running and hides it from non-admins', function () {
    $system = SoftwareSystem::factory()->azdo()->create();
    StaticAnalysisRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'running', 'counts_json' => []]);

    Livewire::actingAs($this->admin)
        ->test(ViewSoftwareSystem::class, ['record' => $system->getRouteKey()])
        ->callAction('runStaticAnalysis')
        ->assertNotified('A static analysis run is already in progress.');

    Queue::assertNothingPushed();

    $sync = User::factory()->create();
    $sync->syncRoles(['Sync']);

    Livewire::actingAs($sync)
        ->test(ViewSoftwareSystem::class, ['record' => $system->getRouteKey()])
        ->assertActionHidden('runStaticAnalysis');
});
