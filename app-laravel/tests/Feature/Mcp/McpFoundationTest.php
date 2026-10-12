<?php

use App\Audit\AuditLog;
use App\Mcp\McpActor;
use App\Mcp\McpActorException;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\WhoAmITool;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fixtures\Mcp\AuditingTestTool;
use Tests\Fixtures\Mcp\ExplodingTestTool;
use Tests\Fixtures\Mcp\FoundationTestServer;
use Tests\Fixtures\Mcp\GatedTestTool;
use Tests\Fixtures\Mcp\PaginationTestTool;
use Tests\Fixtures\Mcp\ScopeTestTool;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    putenv(McpActor::ENV);
    app()->forgetInstance(McpActor::class);
});

afterEach(function () {
    putenv(McpActor::ENV);
});

function mcpUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->syncRoles([$role]);

    return $user;
}

it('refuses to resolve an actor when MCP_USER_EMAIL is not set', function () {
    app(McpActor::class)->resolve();
})->throws(McpActorException::class, 'MCP_USER_EMAIL is not set');

it('refuses an unknown actor email', function () {
    putenv(McpActor::ENV . '=nobody@example.com');

    app(McpActor::class)->resolve();
})->throws(McpActorException::class, 'No user with email nobody@example.com.');

it('refuses a disabled actor', function () {
    mcpUser('Triage', ['email' => 'off@example.com', 'is_disabled' => true]);
    putenv(McpActor::ENV . '=off@example.com');

    app(McpActor::class)->resolve();
})->throws(McpActorException::class, 'User off@example.com is disabled.');

it('resolves the actor case-insensitively from the process environment', function () {
    $user = mcpUser('Triage', ['email' => 'analyst@example.com']);
    putenv(McpActor::ENV . '=  Analyst@Example.com ');

    expect(app(McpActor::class)->resolve()->is($user))->toBeTrue();
});

it('reports the acting user, roles and permissions through whoami', function () {
    $user = mcpUser('Triage', ['email' => 'analyst@example.com']);

    AppSecScoutServer::actingAs($user)->tool(WhoAmITool::class)
        ->assertOk()
        ->assertName('whoami')
        ->assertSee(['analyst@example.com', 'Triage', 'alerts.bulk-edit']);
});

it('acts as the MCP_USER_EMAIL user when no user is authenticated', function () {
    mcpUser('Reader', ['email' => 'reader@example.com']);
    putenv(McpActor::ENV . '=reader@example.com');

    AppSecScoutServer::tool(WhoAmITool::class)
        ->assertOk()
        ->assertSee(['reader@example.com', 'Reader']);
});

it('returns a tool error when the actor cannot be resolved', function () {
    AppSecScoutServer::tool(WhoAmITool::class)
        ->assertHasErrors(['MCP_USER_EMAIL is not set']);
});

it('refuses a disabled authenticated user', function () {
    $user = mcpUser('Admin', ['email' => 'gone@example.com', 'is_disabled' => true]);

    AppSecScoutServer::actingAs($user)->tool(WhoAmITool::class)
        ->assertHasErrors(['User gone@example.com is disabled.']);
});

it('refuses a tool when the user lacks its permission', function () {
    FoundationTestServer::actingAs(mcpUser('Triage'))->tool(GatedTestTool::class)
        ->assertHasErrors(['Permission denied: this tool requires the admin.queue permission.']);

    FoundationTestServer::actingAs(mcpUser('Admin'))->tool(GatedTestTool::class)
        ->assertOk()
        ->assertSee('gated tool ran');
});

it('attributes audit entries to the acting user and tags them with the mcp channel', function () {
    $user = mcpUser('Triage');

    FoundationTestServer::actingAs($user)->tool(AuditingTestTool::class)->assertOk();

    $entry = AuditLog::query()->where('action', 'mcp_test_action')->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->payload_json)->toBe(['value' => 1, 'channel' => 'mcp']);
});

it('logs unexpected failures as mcp errors and surfaces the message', function () {
    FoundationTestServer::actingAs(mcpUser('Triage'))->tool(ExplodingTestTool::class)
        ->assertHasErrors(['Upstream exploded']);

    $error = ErrorLog::query()->where('channel', 'mcp')->sole();

    expect($error->message)->toBe('Upstream exploded')
        ->and($error->context_json['tool'])->toBe('exploding-test');
});

it('resolves an asset, a system and a container to their active systems', function () {
    $user = mcpUser('Reader');
    $asset = SoftwareAsset::factory()->create();
    $systemA = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'A']);
    $systemB = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'B']);
    SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'C', 'removed_at' => now()]);
    $container = SecurityContainer::factory()->forSystem($systemB)->create();

    FoundationTestServer::actingAs($user)->tool(ScopeTestTool::class, ['asset_id' => $asset->id])
        ->assertStructuredContent(['system_ids' => [$systemA->id, $systemB->id]]);

    FoundationTestServer::actingAs($user)->tool(ScopeTestTool::class, ['system_id' => $systemA->id])
        ->assertStructuredContent(['system_ids' => [$systemA->id]]);

    FoundationTestServer::actingAs($user)->tool(ScopeTestTool::class, ['container_id' => $container->id])
        ->assertStructuredContent(['system_ids' => [$systemB->id]]);
});

it('rejects unknown scope ids and ambiguous scopes', function () {
    $user = mcpUser('Reader');
    $system = SoftwareSystem::factory()->create();

    FoundationTestServer::actingAs($user)->tool(ScopeTestTool::class, ['system_id' => 999999])
        ->assertHasErrors(['No software system with id 999999.']);

    FoundationTestServer::actingAs($user)->tool(ScopeTestTool::class, [])
        ->assertHasErrors(['Provide exactly one of asset_id, system_id or container_id.']);

    FoundationTestServer::actingAs($user)->tool(ScopeTestTool::class, ['system_id' => $system->id, 'asset_id' => SoftwareAsset::factory()->create()->id])
        ->assertHasErrors(['Provide exactly one of asset_id, system_id or container_id.']);
});

it('paginates within bounds and reports whether more rows exist', function () {
    $user = mcpUser('Reader');
    $systems = SoftwareSystem::factory()->count(3)->create();

    FoundationTestServer::actingAs($user)->tool(PaginationTestTool::class, ['per_page' => 2])
        ->assertStructuredContent([
            'items' => [['id' => $systems[0]->id], ['id' => $systems[1]->id]],
            'page' => 1,
            'per_page' => 2,
            'total' => 3,
            'has_more' => true,
        ]);

    FoundationTestServer::actingAs($user)->tool(PaginationTestTool::class, ['per_page' => 2, 'page' => 2])
        ->assertStructuredContent([
            'items' => [['id' => $systems[2]->id]],
            'page' => 2,
            'per_page' => 2,
            'total' => 3,
            'has_more' => false,
        ]);

    FoundationTestServer::actingAs($user)->tool(PaginationTestTool::class, ['per_page' => 51])
        ->assertHasErrors();
});
