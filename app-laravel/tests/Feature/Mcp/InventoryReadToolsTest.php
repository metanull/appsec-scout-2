<?php

use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Inventory\GetContainerLinksTool;
use App\Mcp\Tools\Inventory\ListSecurityContainersTool;
use App\Mcp\Tools\Inventory\ListSoftwareAssetsTool;
use App\Mcp\Tools\Inventory\ListSoftwareSystemsTool;
use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Sources\Context\SourceContextFacts;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->reader = mcpUser('Reader');
});

it('lists software assets with their system counts, filtered and paginated', function () {
    $payments = SoftwareAsset::factory()->create(['name' => 'Payments']);
    SoftwareAsset::factory()->create(['name' => 'Billing']);
    SoftwareAsset::factory()->create(['name' => 'Payroll']);
    SoftwareSystem::factory()->count(2)->create(['software_asset_id' => $payments->id]);

    AppSecScoutServer::actingAs($this->reader)->tool(ListSoftwareAssetsTool::class, ['search' => 'Pay'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 2)
            ->where('items.0.name', 'Payments')
            ->where('items.0.systems_count', 2)
            ->where('items.1.name', 'Payroll')
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(ListSoftwareAssetsTool::class, ['per_page' => 1])
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 3)
            ->where('has_more', true)
            ->where('items.0.name', 'Billing')
            ->etc());
});

it('lists software systems by asset and source, hiding removed ones by default', function () {
    $asset = SoftwareAsset::factory()->create(['name' => 'Payments']);
    $azdo = SoftwareSystem::factory()->azdo()->create(['name' => 'Payments API', 'software_asset_id' => $asset->id]);
    SoftwareSystem::factory()->create(['name' => 'Payments Web', 'source_id' => 'detectify', 'software_asset_id' => $asset->id]);
    SoftwareSystem::factory()->azdo()->create(['name' => 'Payments Legacy', 'software_asset_id' => $asset->id, 'removed_at' => now()]);
    SoftwareSystem::factory()->azdo()->create(['name' => 'Unrelated']);
    SecurityContainer::factory()->forSystem($azdo)->count(2)->create();

    AppSecScoutServer::actingAs($this->reader)->tool(ListSoftwareSystemsTool::class, ['asset_id' => $asset->id, 'source_id' => 'azdo'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 1)
            ->where('items.0.id', $azdo->id)
            ->where('items.0.asset', ['id' => $asset->id, 'name' => 'Payments'])
            ->where('items.0.containers_count', 2)
            ->where('items.0.removed_at', null)
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(ListSoftwareSystemsTool::class, ['asset_id' => $asset->id, 'include_removed' => true])
        ->assertStructuredContent(fn ($json) => $json->where('total', 3)->etc());
});

it('rejects an unknown source or asset when listing systems', function () {
    AppSecScoutServer::actingAs($this->reader)->tool(ListSoftwareSystemsTool::class, ['source_id' => 'nope'])
        ->assertHasErrors();

    AppSecScoutServer::actingAs($this->reader)->tool(ListSoftwareSystemsTool::class, ['asset_id' => 999999])
        ->assertHasErrors(['No software asset with id 999999.']);
});

it('lists security containers by system or asset, ordered by system then name', function () {
    $asset = SoftwareAsset::factory()->create();
    $alpha = SoftwareSystem::factory()->create(['name' => 'Alpha', 'software_asset_id' => $asset->id]);
    $beta = SoftwareSystem::factory()->create(['name' => 'Beta', 'software_asset_id' => $asset->id]);
    $betaRepo = SecurityContainer::factory()->forSystem($beta)->create(['name' => 'a-repo']);
    $alphaRepo = SecurityContainer::factory()->forSystem($alpha)->create(['name' => 'z-repo']);
    SecurityContainer::factory()->forSystem($alpha)->create(['name' => 'gone', 'removed_at' => now()]);
    SecurityContainer::factory()->create(['name' => 'elsewhere']);

    AppSecScoutServer::actingAs($this->reader)->tool(ListSecurityContainersTool::class, ['asset_id' => $asset->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 2)
            ->where('items.0.id', $alphaRepo->id)
            ->where('items.0.system.name', 'Alpha')
            ->where('items.1.id', $betaRepo->id)
            ->etc());

    AppSecScoutServer::actingAs($this->reader)->tool(ListSecurityContainersTool::class, ['system_id' => $alpha->id, 'include_removed' => true])
        ->assertStructuredContent(fn ($json) => $json->where('total', 2)->etc());
});

it('returns a container\'s clone URL, repository mappings and links', function () {
    $system = SoftwareSystem::factory()->azdo()->create(['name' => 'Payments API', 'url' => 'https://payments.example.com']);
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'payments-service',
        'kind' => 'repository',
        'url' => 'https://dev.azure.com/org/payments/_git/payments-service',
        'metadata' => [
            SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL => 'https://org@dev.azure.com/org/payments/_git/payments-service',
            SourceContextFacts::CODE_DEFAULT_BRANCH => 'develop',
        ],
    ]);
    RepositoryMapping::factory()->forContainer($container)->create(['repository_name' => 'payments-service']);
    $container->curatedLinks()->create(['label' => 'Production', 'kind' => 'other', 'url' => 'https://pay.example.com']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetContainerLinksTool::class, ['container_id' => $container->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('container.clone_url', 'https://org@dev.azure.com/org/payments/_git/payments-service')
            ->where('container.default_branch', 'develop')
            ->where('system.url', 'https://payments.example.com')
            ->where('repository_mappings.0.of', 'container')
            ->where('repository_mappings.0.repository_name', 'payments-service')
            ->etc())
        ->assertSee(['https://pay.example.com', 'Production']);
});

it('rejects an unknown container', function () {
    AppSecScoutServer::actingAs($this->reader)->tool(GetContainerLinksTool::class, ['container_id' => 999999])
        ->assertHasErrors(['No security container with id 999999.']);
});

it('refuses every inventory tool without alerts.view', function (string $tool, array $arguments) {
    AppSecScoutServer::actingAs(mcpUser(null))->tool($tool, $arguments)
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
})->with([
    'assets' => [ListSoftwareAssetsTool::class, []],
    'systems' => [ListSoftwareSystemsTool::class, []],
    'containers' => [ListSecurityContainersTool::class, []],
    'links' => [GetContainerLinksTool::class, ['container_id' => 1]],
]);
