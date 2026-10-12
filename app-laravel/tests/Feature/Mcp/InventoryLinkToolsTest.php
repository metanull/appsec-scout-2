<?php

use App\Audit\AuditLog;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Inventory\GetContainerLinksTool;
use App\Mcp\Tools\Inventory\LinkSystemToAssetTool;
use App\Mcp\Tools\Inventory\ListSecurityContainersTool;
use App\Mcp\Tools\Inventory\MoveContainerToSystemTool;
use App\Mcp\Tools\Inventory\RestoreContainerSourceLinkTool;
use App\Mcp\Tools\Inventory\UnlinkSystemFromAssetTool;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->plan = mcpUser('Plan');
});

function expectMcpAudit(string $action, int $userId): void
{
    $audit = AuditLog::query()->where('action', $action)->sole();

    expect($audit->user_id)->toBe($userId)
        ->and($audit->payload_json['channel'])->toBe('mcp')
        ->and($audit->payload_json['author_id'])->toBe($userId);
}

it('links a system to an asset after a dry run, then unlinks it', function () {
    $previous = SoftwareAsset::factory()->create();
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $previous->id]);

    AppSecScoutServer::actingAs($this->plan)->tool(LinkSystemToAssetTool::class, ['system_id' => $system->id, 'asset_id' => $asset->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('dry_run', true)->where('previous_asset_id', $previous->id)->etc());

    expect($system->fresh()?->software_asset_id)->toBe($previous->id);

    AppSecScoutServer::actingAs($this->plan)->tool(LinkSystemToAssetTool::class, ['system_id' => $system->id, 'asset_id' => $asset->id, 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', false)
            ->where('system_id', $system->id)
            ->where('asset_id', $asset->id)
            ->where('previous_asset_id', $previous->id)
            ->where('restamped', ['local_findings' => 0, 'software_components' => 0]));

    expect($system->fresh()?->software_asset_id)->toBe($asset->id);
    expectMcpAudit('software_system_linked_to_asset', $this->plan->id);

    AppSecScoutServer::actingAs($this->plan)->tool(UnlinkSystemFromAssetTool::class, ['system_id' => $system->id, 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('previous_asset_id', $asset->id)->etc());

    expect($system->fresh()?->software_asset_id)->toBeNull();
    expectMcpAudit('software_system_unlinked_from_asset', $this->plan->id);

    AppSecScoutServer::actingAs($this->plan)->tool(UnlinkSystemFromAssetTool::class, ['system_id' => $system->id])
        ->assertHasErrors(["Software system #{$system->id} is not linked to an asset."]);
});

it('moves a container to another system after a dry run, then restores its source link', function () {
    $source = SoftwareSystem::factory()->create();
    $target = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($source)->create();
    SecurityEvent::factory()->forContainer($container)->count(2)->create();

    AppSecScoutServer::actingAs($this->plan)->tool(MoveContainerToSystemTool::class, ['container_id' => $container->id, 'system_id' => $target->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', true)
            ->where('manual_link', true)
            ->where('would_move', ['events' => 2, 'local_findings' => 0, 'software_components' => 0])
            ->etc());

    expect($container->fresh()?->software_system_id)->toBe($source->id);

    AppSecScoutServer::actingAs($this->plan)->tool(MoveContainerToSystemTool::class, ['container_id' => $container->id, 'system_id' => $target->id, 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', false)
            ->where('container_id', $container->id)
            ->where('previous_system_id', $source->id)
            ->where('system_id', $target->id)
            ->where('manual_link', true)
            ->where('moved', ['events' => 2, 'local_findings' => 0, 'software_components' => 0]));

    expectMcpAudit('security_container_relinked', $this->plan->id);

    AppSecScoutServer::actingAs($this->plan)->tool(RestoreContainerSourceLinkTool::class, ['container_id' => $container->id, 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('system_id', $source->id)->where('manual_link', false)->etc());

    expect($container->fresh()?->isSystemOverridden())->toBeFalse();
    expectMcpAudit('security_container_link_reset', $this->plan->id);
});

it('surfaces refused moves as tool errors, on a dry run as well', function () {
    $container = SecurityContainer::factory()->create();
    $removed = SoftwareSystem::factory()->create(['removed_at' => now()]);

    AppSecScoutServer::actingAs($this->plan)->tool(MoveContainerToSystemTool::class, ['container_id' => $container->id, 'system_id' => $container->software_system_id])
        ->assertHasErrors(["Security container #{$container->id} is already linked to software system #{$container->software_system_id}."]);

    AppSecScoutServer::actingAs($this->plan)->tool(MoveContainerToSystemTool::class, ['container_id' => $container->id, 'system_id' => $removed->id, 'dry_run' => false])
        ->assertHasErrors(["Software system #{$removed->id} was removed from its source."]);

    AppSecScoutServer::actingAs($this->plan)->tool(RestoreContainerSourceLinkTool::class, ['container_id' => $container->id])
        ->assertHasErrors(["Security container #{$container->id} already follows its source system."]);

    expect(AuditLog::query()->where('action', 'like', 'security_container_%')->exists())->toBeFalse();
});

it('refuses every link tool to a user who cannot curate', function () {
    $triage = mcpUser('Triage');
    $system = SoftwareSystem::factory()->create(['software_asset_id' => SoftwareAsset::factory()]);
    $container = SecurityContainer::factory()->relinked(SoftwareSystem::factory()->create(), $system)->create();
    $denied = 'Permission denied: this tool requires the context.curate permission.';

    AppSecScoutServer::actingAs($triage)->tool(LinkSystemToAssetTool::class, ['system_id' => $system->id, 'asset_id' => SoftwareAsset::factory()->create()->id])
        ->assertHasErrors([$denied]);
    AppSecScoutServer::actingAs($triage)->tool(UnlinkSystemFromAssetTool::class, ['system_id' => $system->id])
        ->assertHasErrors([$denied]);
    AppSecScoutServer::actingAs($triage)->tool(MoveContainerToSystemTool::class, ['container_id' => $container->id, 'system_id' => SoftwareSystem::factory()->create()->id])
        ->assertHasErrors([$denied]);
    AppSecScoutServer::actingAs($triage)->tool(RestoreContainerSourceLinkTool::class, ['container_id' => $container->id])
        ->assertHasErrors([$denied]);
});

it('shows manual links in the container list and links tools', function () {
    $source = SoftwareSystem::factory()->create(['name' => 'Reported system']);
    $target = SoftwareSystem::factory()->create(['name' => 'Chosen system']);
    $manual = SecurityContainer::factory()->relinked($source, $target)->create(['name' => 'a-manual']);
    SecurityContainer::factory()->forSystem($target)->create(['name' => 'b-from-source']);

    AppSecScoutServer::actingAs($this->plan)->tool(ListSecurityContainersTool::class, ['system_id' => $target->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.manual_link', true)
            ->where('items.0.source_system', ['id' => $source->id, 'name' => 'Reported system'])
            ->where('items.1.manual_link', false)
            ->where('items.1.source_system', null)
            ->etc());

    AppSecScoutServer::actingAs($this->plan)->tool(GetContainerLinksTool::class, ['container_id' => $manual->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('container.manual_link', true)
            ->where('container.source_system.id', $source->id)
            ->where('system.id', $target->id)
            ->etc());
});
