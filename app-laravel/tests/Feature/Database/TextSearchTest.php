<?php

use App\Database\TextSearch;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Inventory\ListSecurityContainersTool;
use App\Mcp\Tools\Inventory\ListSoftwareAssetsTool;
use App\Mcp\Tools\Inventory\ListSoftwareSystemsTool;
use App\Mcp\Tools\Sbom\ListSbomComponentsTool;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use Database\Seeders\RolePermissionSeeder;

it('matches any listed column ignoring case, and leaves the query alone for a blank term', function () {
    $payments = SoftwareSystem::factory()->create(['name' => 'Payments API', 'description' => null]);
    $described = SoftwareSystem::factory()->create(['name' => 'Ledger', 'description' => 'Handles PAYMENTS reconciliation']);
    SoftwareSystem::factory()->create(['name' => 'Billing', 'description' => null]);

    $ids = fn (?string $term): array => TextSearch::apply(SoftwareSystem::query(), ['name', 'description'], $term)->orderBy('id')->pluck('id')->all();

    expect($ids('payments'))->toBe([$payments->id, $described->id])
        ->and($ids('PAYMENTS api'))->toBe([$payments->id])
        ->and($ids('  '))->toHaveCount(3)
        ->and($ids(null))->toHaveCount(3);
});

it('treats LIKE wildcards and the escape character in the term literally', function () {
    $percent = SoftwareSystem::factory()->create(['name' => 'Coverage 100% done']);
    $underscore = SoftwareSystem::factory()->create(['name' => 'snake_case service']);
    $bang = SoftwareSystem::factory()->create(['name' => 'Alert! system']);
    SoftwareSystem::factory()->create(['name' => 'Coverage 1000 done snakeXcase']);

    $ids = fn (string $term): array => TextSearch::apply(SoftwareSystem::query(), ['name'], $term)->pluck('id')->all();

    expect($ids('100%'))->toBe([$percent->id])
        ->and($ids('e_c'))->toBe([$underscore->id])
        ->and($ids('t! s'))->toBe([$bang->id]);
});

it('searches alerts and local findings ignoring case, JSON metadata included', function () {
    $byTitle = SecurityEvent::factory()->create(['title' => 'Payments token leak', 'description' => null, 'metadata' => null]);
    $byMetadata = SecurityEvent::factory()->create(['title' => 'Other', 'description' => null, 'metadata' => ['package' => 'Newtonsoft.JSON']]);
    SecurityEvent::factory()->create(['title' => 'Unrelated', 'description' => null, 'metadata' => null]);

    $system = SoftwareSystem::factory()->create();
    $finding = LocalFinding::query()->create([
        'owner_type' => SoftwareSystem::class, 'owner_id' => $system->id, 'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Payments API key', 'file_path' => 'config.yml',
        'metadata' => ['scanner' => 'Gitleaks'],
    ]);

    expect(SecurityEventTableQuery::applySearch(SecurityEvent::query(), 'PAYMENTS')->pluck('id')->all())->toBe([$byTitle->id])
        ->and(SecurityEventTableQuery::applySearch(SecurityEvent::query(), 'newtonsoft.json')->pluck('id')->all())->toBe([$byMetadata->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'payments')->pluck('id')->all())->toBe([$finding->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'gitleaks')->pluck('id')->all())->toBe([$finding->id]);
});

it('searches the MCP inventory and SBOM lists ignoring case', function () {
    (new RolePermissionSeeder)->run();
    $reader = mcpUser('Reader');
    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    SoftwareAsset::factory()->create(['name' => 'Billing']);
    $system = SoftwareSystem::factory()->create(['name' => 'Payments API']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'Payments-Web']);
    $component = SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class, 'owner_id' => $container->id, 'software_system_id' => $system->id,
        'name' => 'Newtonsoft.Json', 'version' => '13.0.1', 'purl' => 'pkg:nuget/Newtonsoft.Json@13.0.1',
    ]);

    expect(mcpItemIds(AppSecScoutServer::actingAs($reader)->tool(ListSoftwareAssetsTool::class, ['search' => 'payments'])))->toBe([$asset->id])
        ->and(mcpItemIds(AppSecScoutServer::actingAs($reader)->tool(ListSoftwareSystemsTool::class, ['search' => 'PAYMENTS'])))->toBe([$system->id])
        ->and(mcpItemIds(AppSecScoutServer::actingAs($reader)->tool(ListSecurityContainersTool::class, ['search' => 'payments-web'])))->toBe([$container->id])
        ->and(mcpItemIds(AppSecScoutServer::actingAs($reader)->tool(ListSbomComponentsTool::class, ['system_id' => $system->id, 'search' => 'newtonsoft'])))->toBe([$component->id]);
});
