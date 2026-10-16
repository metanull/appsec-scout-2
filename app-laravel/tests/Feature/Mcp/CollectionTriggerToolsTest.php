<?php

use App\Audit\AuditLog;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Collection\CollectRepositoriesTool;
use App\Mcp\Tools\Collection\FetchAlertsTool;
use App\Mcp\Tools\Collection\RunStaticAnalysisTool;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sync\FetchSystemJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    Queue::fake();
    $this->admin = mcpUser('Admin');
});

it('queues a single-system fetch for every active system of an asset', function () {
    $asset = SoftwareAsset::factory()->create();
    $azdo = SoftwareSystem::factory()->azdo()->create(['software_asset_id' => $asset->id, 'name' => 'A']);
    $web = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'source_id' => 'detectify', 'name' => 'B']);
    SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'removed_at' => now(), 'name' => 'C']);

    AppSecScoutServer::actingAs($this->admin)->tool(FetchAlertsTool::class, ['asset_id' => $asset->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('queued.0.system_id', $azdo->id)
            ->where('queued.1.system_id', $web->id)
            ->has('queued', 2)
            ->etc());

    Queue::assertPushed(FetchSystemJob::class, 2);
    Queue::assertPushed(FetchSystemJob::class, fn (FetchSystemJob $job): bool => $job->softwareSystemId === $web->id);

    $audits = AuditLog::query()->where('action', 'operations.dispatch_system_fetch')->get();

    expect($audits)->toHaveCount(2)
        ->and($audits->every(fn (AuditLog $audit): bool => $audit->payload_json['channel'] === 'mcp'))->toBeTrue();
});

it('fetches the system of a container', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    AppSecScoutServer::actingAs(mcpUser('Sync'))->tool(FetchAlertsTool::class, ['container_id' => $container->id])
        ->assertOk();

    Queue::assertPushed(FetchSystemJob::class, fn (FetchSystemJob $job): bool => $job->softwareSystemId === $system->id);
});

it('fetches a re-linked container from the system its source reports it under', function () {
    $source = SoftwareSystem::factory()->create();
    $linkedTo = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->relinked($source, $linkedTo)->create();

    AppSecScoutServer::actingAs(mcpUser('Sync'))->tool(FetchAlertsTool::class, ['container_id' => $container->id])
        ->assertOk();

    Queue::assertPushed(FetchSystemJob::class, 1);
    Queue::assertPushed(FetchSystemJob::class, fn (FetchSystemJob $job): bool => $job->softwareSystemId === $source->id);
});

it('collects the first AzDO system of a scope and reports the rest', function () {
    $asset = SoftwareAsset::factory()->create();
    $first = SoftwareSystem::factory()->azdo()->create(['software_asset_id' => $asset->id, 'name' => 'Alpha']);
    $second = SoftwareSystem::factory()->azdo()->create(['software_asset_id' => $asset->id, 'name' => 'Beta']);
    $web = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'source_id' => 'asoc', 'name' => 'Gamma']);

    AppSecScoutServer::actingAs($this->admin)->tool(CollectRepositoriesTool::class, ['asset_id' => $asset->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('queued.0.system_id', $first->id)
            ->where('not_queued.0.system_id', $second->id)
            ->where('skipped.0.system_id', $web->id)
            ->etc());

    Queue::assertPushed(DispatchRepositoryCollectionRunsJob::class, 1);
    Queue::assertPushed(DispatchRepositoryCollectionRunsJob::class, fn (DispatchRepositoryCollectionRunsJob $job): bool => $job->softwareSystemId === $first->id);
});

it('runs static analysis with force for an AzDO system', function () {
    $system = SoftwareSystem::factory()->azdo()->create();

    AppSecScoutServer::actingAs($this->admin)->tool(RunStaticAnalysisTool::class, ['system_id' => $system->id, 'force' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('force', true)->where('queued.0.system_id', $system->id)->etc());

    Queue::assertPushed(DispatchStaticAnalysisRunsJob::class, fn (DispatchStaticAnalysisRunsJob $job): bool => $job->softwareSystemId === $system->id && $job->force);

    // toEqual, not toBe: MySQL's JSON type reorders object keys, so key order is not stable.
    expect(AuditLog::query()->where('action', 'operations.run_static_analysis')->sole()->payload_json)
        ->toEqual(['force' => true, 'software_system_id' => $system->id, 'channel' => 'mcp']);
});

it('queues nothing while a run of the same kind is in progress', function () {
    $system = SoftwareSystem::factory()->azdo()->create();
    RepositoryCollectionRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'running', 'counts_json' => []]);
    StaticAnalysisRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'running', 'counts_json' => []]);

    AppSecScoutServer::actingAs($this->admin)->tool(CollectRepositoriesTool::class, ['system_id' => $system->id])
        ->assertHasErrors(['A repository collection run is already in progress; nothing was queued. Try again once it finishes.']);
    AppSecScoutServer::actingAs($this->admin)->tool(RunStaticAnalysisTool::class, ['system_id' => $system->id])
        ->assertHasErrors(['A static analysis run is already in progress; nothing was queued. Try again once it finishes.']);

    Queue::assertNothingPushed();
});

it('refuses a scope without an eligible system', function () {
    AppSecScoutServer::actingAs($this->admin)->tool(CollectRepositoriesTool::class, ['system_id' => SoftwareSystem::factory()->create(['source_id' => 'detectify'])->id])
        ->assertHasErrors(['No Azure DevOps system in this scope: repository collection only covers Azure DevOps repositories.']);

    AppSecScoutServer::actingAs($this->admin)->tool(FetchAlertsTool::class, ['system_id' => SoftwareSystem::factory()->create(['removed_at' => now()])->id])
        ->assertHasErrors(['No active software system in this scope.']);

    AppSecScoutServer::actingAs($this->admin)->tool(FetchAlertsTool::class, [])
        ->assertHasErrors(['Provide exactly one of asset_id, system_id or container_id.']);

    Queue::assertNothingPushed();
});

it('applies the Operations page permissions', function () {
    $system = SoftwareSystem::factory()->azdo()->create();
    $sync = mcpUser('Sync');
    $plan = mcpUser('Plan');

    AppSecScoutServer::actingAs($plan)->tool(FetchAlertsTool::class, ['system_id' => $system->id])
        ->assertHasErrors(['Permission denied: this tool requires the admin.queue or work-items.sync permission.']);
    AppSecScoutServer::actingAs($sync)->tool(CollectRepositoriesTool::class, ['system_id' => $system->id])
        ->assertHasErrors(['Permission denied: this tool requires the admin.queue permission.']);
    AppSecScoutServer::actingAs($sync)->tool(RunStaticAnalysisTool::class, ['system_id' => $system->id])
        ->assertHasErrors(['Permission denied: this tool requires the admin.queue permission.']);

    Queue::assertNothingPushed();
});
