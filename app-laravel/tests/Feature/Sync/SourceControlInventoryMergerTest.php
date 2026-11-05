<?php

use App\Audit\AuditLog;
use App\Models\CuratedLink;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Sync\SourceControlInventoryMerger;
use Carbon\CarbonImmutable;

/** An AzDO project as the AzDO source stores it, and the copy the AzDO Repos inventory made of it. */
function azdoProjectAndDuplicate(string $projectId = 'proj-1'): array
{
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => $projectId, 'name' => 'ARC']);
    $duplicate = SoftwareSystem::factory()->create(['source_id' => 'azdo-repos', 'source_system_id' => $projectId, 'name' => 'ARC']);

    return [$system, $duplicate];
}

it('folds a duplicate container into its twin, keeping the disabled flag, last commit and operator links', function () {
    [$system, $duplicate] = azdoProjectAndDuplicate();
    $asset = SoftwareAsset::factory()->create();
    $duplicate->update(['software_asset_id' => $asset->id]);

    $twin = SecurityContainer::factory()->forSystem($system)->create(['source_container_id' => 'repo-1', 'name' => 'poc-country']);
    $copy = SecurityContainer::factory()->forSystem($duplicate)->create([
        'source_container_id' => 'repo-1', 'name' => 'poc-country', 'is_disabled' => true,
        'last_commit_sha' => 'abc123', 'last_commit_at' => CarbonImmutable::parse('2024-06-28T10:00:00Z'), 'last_commit_author' => 'Ada Author',
    ]);
    $link = CuratedLink::factory()->create(['owner_type' => SecurityContainer::class, 'owner_id' => $copy->id]);

    $result = app(SourceControlInventoryMerger::class)->merge(dryRun: false);

    $twin->refresh();

    expect($result)->toMatchArray(['systems_merged' => 1, 'systems_rekeyed' => 0, 'containers_merged' => 1, 'containers_moved' => 0, 'skipped' => []])
        ->and(SecurityContainer::query()->find($copy->id))->toBeNull()
        ->and(SoftwareSystem::query()->find($duplicate->id))->toBeNull()
        ->and($twin->is_disabled)->toBeTrue()
        ->and($twin->last_commit_sha)->toBe('abc123')
        ->and($twin->last_commit_author)->toBe('Ada Author')
        ->and($link->fresh()?->owner_id)->toBe($twin->id)
        ->and($system->fresh()?->software_asset_id)->toBe($asset->id)
        ->and(AuditLog::query()->where('action', 'source_control_inventory_duplicates_merged')->count())->toBe(1);
});

it('moves a duplicate container that has no twin, and re-keys a duplicate system that has none', function () {
    [$system, $duplicate] = azdoProjectAndDuplicate();
    $onlyInDuplicate = SecurityContainer::factory()->forSystem($duplicate)->create(['source_container_id' => 'repo-new']);
    $lonely = SoftwareSystem::factory()->create(['source_id' => 'azdo-repos', 'source_system_id' => 'proj-2']);

    $result = app(SourceControlInventoryMerger::class)->merge(dryRun: false);

    expect($result['containers_moved'])->toBe(1)
        ->and($result['systems_rekeyed'])->toBe(1)
        ->and($onlyInDuplicate->fresh()?->software_system_id)->toBe($system->id)
        ->and($onlyInDuplicate->fresh()?->source_software_system_id)->toBe($system->id)
        ->and($lonely->fresh()?->source_id)->toBe('azdo')
        ->and(SoftwareSystem::query()->where('source_id', 'azdo-repos')->exists())->toBeFalse();
});

it('never deletes a duplicate that holds findings: it is reported instead', function () {
    [$system, $duplicate] = azdoProjectAndDuplicate();
    SecurityContainer::factory()->forSystem($system)->create(['source_container_id' => 'repo-1']);
    $copy = SecurityContainer::factory()->forSystem($duplicate)->create(['source_container_id' => 'repo-1']);
    $copy->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r1', 'title' => 'x', 'file_path' => 'a', 'status' => EventState::Open]);

    $result = app(SourceControlInventoryMerger::class)->merge(dryRun: false);

    expect($result['containers_merged'])->toBe(0)
        ->and($result['systems_merged'])->toBe(0)
        ->and($result['skipped'])->toHaveCount(2)
        ->and($copy->fresh())->not->toBeNull()
        ->and($duplicate->fresh())->not->toBeNull();
});

it('changes nothing in a dry run', function () {
    [$system, $duplicate] = azdoProjectAndDuplicate();
    SecurityContainer::factory()->forSystem($system)->create(['source_container_id' => 'repo-1']);
    $copy = SecurityContainer::factory()->forSystem($duplicate)->create(['source_container_id' => 'repo-1', 'is_disabled' => true]);

    $this->artisan('assets:merge-inventory-duplicates', ['--dry-run' => true])
        ->expectsOutputToContain('systems_merged=1 systems_rekeyed=0 containers_merged=1 containers_moved=0 skipped=0')
        ->assertSuccessful();

    expect($copy->fresh())->not->toBeNull()
        ->and($duplicate->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'source_control_inventory_duplicates_merged')->exists())->toBeFalse();
});
