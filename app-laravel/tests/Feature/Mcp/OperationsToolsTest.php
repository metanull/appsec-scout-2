<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Operations\GetOperationsStatusTool;
use App\Mcp\Tools\Operations\ListErrorsTool;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\Models\SyncRun;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function errorEntry(array $attributes = []): ErrorLog
{
    return ErrorLog::query()->create([
        'level' => 'error',
        'channel' => 'sync',
        'message' => 'Upstream returned 500',
        'context_json' => ['source_id' => 'azdo'],
        'trace' => str_repeat('#0 frame' . PHP_EOL, 1000),
        'occurred_at' => now()->subHour(),
        ...$attributes,
    ]);
}

function collectionRun(string $model, ?SoftwareSystem $system, string $status, int $minutesAgo): RepositoryCollectionRun|StaticAnalysisRun
{
    return $model::query()->create([
        'source_control_id' => 'azdo-repos',
        'software_system_id' => $system?->id,
        'started_at' => now()->subMinutes($minutesAgo),
        'finished_at' => $status === 'running' ? null : now()->subMinutes($minutesAgo - 1),
        'status' => $status,
        'counts_json' => ['repositories' => 3],
        'error_message' => $status === 'failure' ? 'clone failed' : null,
    ]);
}

it('reports source runs, collection runs, error counts and the queue', function () {
    SyncRun::query()->create(['source_id' => 'azdo', 'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3), 'status' => 'success', 'counts_json' => []]);
    SyncRun::query()->create(['source_id' => 'azdo', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'status' => 'failure', 'counts_json' => ['events_failed' => 2], 'error_message' => 'boom']);
    collectionRun(RepositoryCollectionRun::class, null, 'running', 5);
    collectionRun(StaticAnalysisRun::class, null, 'failure', 30);
    errorEntry();
    errorEntry(['channel' => 'mcp']);
    errorEntry(['occurred_at' => now()->subDays(2)]);

    AppSecScoutServer::actingAs(mcpUser('Sync'))->tool(GetOperationsStatusTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('sources.0.source_id', 'azdo')
            ->where('sources.0.running', false)
            ->where('sources.0.latest_run.status', 'failure')
            ->where('sources.0.latest_run.error_message', 'boom')
            ->whereType('sources.0.last_success_at', 'string')
            ->where('repository_collection.running', true)
            ->where('static_analysis.running', false)
            ->where('static_analysis.recent.0.error_message', 'clone failed')
            ->where('errors_last_24h', ['mcp' => 1, 'sync' => 1])
            ->has('queue.pending')
            ->has('queue.failed')
            ->missing('systems')
            ->etc());
});

it('limits runs and errors to a system scope and reports its last sync', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'synced_at' => now()->subMinutes(10)]);
    $other = SoftwareSystem::factory()->create(['source_id' => 'asoc']);
    SyncRun::query()->create(['source_id' => 'asoc', 'started_at' => now(), 'status' => 'running', 'counts_json' => []]);
    collectionRun(RepositoryCollectionRun::class, $other, 'running', 5);
    $mine = collectionRun(RepositoryCollectionRun::class, $system, 'success', 20);
    errorEntry(['software_system_id' => $system->id]);
    errorEntry(['software_system_id' => $other->id, 'channel' => 'mcp']);

    AppSecScoutServer::actingAs(mcpUser('Sync'))->tool(GetOperationsStatusTool::class, ['system_id' => $system->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('sources', [['source_id' => 'azdo', 'running' => false, 'latest_run' => null, 'last_success_at' => null]])
            ->where('repository_collection.running', false)
            ->where('repository_collection.recent.0.id', $mine->id)
            ->has('repository_collection.recent', 1)
            ->where('systems.0.id', $system->id)
            ->whereType('systems.0.synced_at', 'string')
            ->where('systems.0.errors_last_24h', 1)
            ->where('errors_last_24h', ['sync' => 1])
            ->etc());
});

it('lists errors with filters, newest first, and the trace only on request', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $containerError = errorEntry(['software_system_id' => $system->id, 'security_container_id' => $container->id, 'occurred_at' => now()->subMinutes(5)]);
    $systemError = errorEntry(['software_system_id' => $system->id, 'occurred_at' => now()->subMinutes(10)]);
    errorEntry(['software_system_id' => $system->id, 'security_container_id' => SecurityContainer::factory()->forSystem($system)->create()->id]);
    errorEntry(['message' => 'Token expired', 'level' => 'warning', 'channel' => 'mcp']);
    errorEntry(['occurred_at' => now()->subDays(10)]);
    $admin = mcpUser('Admin');

    expect(mcpItemIds(AppSecScoutServer::actingAs($admin)->tool(ListErrorsTool::class, ['container_id' => $container->id])))
        ->toBe([$containerError->id, $systemError->id]);

    expect(count(mcpItemIds(AppSecScoutServer::actingAs($admin)->tool(ListErrorsTool::class, []))))->toBe(4)
        ->and(count(mcpItemIds(AppSecScoutServer::actingAs($admin)->tool(ListErrorsTool::class, ['since' => now()->subDays(30)->toIso8601String()]))))->toBe(5)
        ->and(count(mcpItemIds(AppSecScoutServer::actingAs($admin)->tool(ListErrorsTool::class, ['channel' => 'mcp', 'level' => 'warning', 'search' => 'token']))))->toBe(1);

    AppSecScoutServer::actingAs($admin)->tool(ListErrorsTool::class, ['container_id' => $container->id])
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.container.id', $container->id)
            ->where('items.0.context', ['source_id' => 'azdo'])
            ->missing('items.0.trace')
            ->etc());

    AppSecScoutServer::actingAs($admin)->tool(ListErrorsTool::class, ['container_id' => $container->id, 'include_trace' => true])
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.trace', fn (string $trace): bool => mb_strlen($trace) <= 4003)
            ->etc());
});

it('applies the Operations page and Error log permissions', function () {
    AppSecScoutServer::actingAs(mcpUser('Reader'))->tool(GetOperationsStatusTool::class, [])
        ->assertHasErrors(['Permission denied: this tool requires the admin.queue or work-items.sync permission.']);
    AppSecScoutServer::actingAs(mcpUser('Sync'))->tool(ListErrorsTool::class, [])
        ->assertHasErrors(['Permission denied: this tool requires the admin.errors permission.']);
});
