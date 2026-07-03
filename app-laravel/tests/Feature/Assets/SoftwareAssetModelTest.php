<?php

use App\Models\Attachment;
use App\Models\CuratedLink;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;

it('links software systems from multiple sources to one asset', function () {
    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);

    $azdo = SoftwareSystem::factory()->azdo()->create(['software_asset_id' => $asset->id]);
    $asoc = SoftwareSystem::factory()->create(['source_id' => 'asoc', 'software_asset_id' => $asset->id]);
    $unrelated = SoftwareSystem::factory()->create();

    expect($asset->softwareSystems()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$azdo->id, $asoc->id])->sort()->values()->all())
        ->and($unrelated->fresh()->software_asset_id)->toBeNull();
});

it('rolls up events across every linked software system', function () {
    $asset = SoftwareAsset::factory()->create();
    $system1 = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $system2 = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);
    $unrelatedSystem = SoftwareSystem::factory()->create();

    SecurityEvent::factory()->count(2)->create(['software_system_id' => $system1->id]);
    SecurityEvent::factory()->create(['software_system_id' => $system2->id]);
    SecurityEvent::factory()->create(['software_system_id' => $unrelatedSystem->id]);

    expect($asset->events()->count())->toBe(3);
});

it('nulls software_asset_id on software systems when the asset is deleted', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);

    $asset->delete();

    expect($system->fresh()->software_asset_id)->toBeNull()
        ->and(SoftwareSystem::query()->whereKey($system->id)->exists())->toBeTrue();
});

it('deletes owned curated links and attachments when the asset is deleted', function () {
    $asset = SoftwareAsset::factory()->create();

    $link = $asset->curatedLinks()->create([
        'label' => 'Runbook',
        'kind' => 'other',
        'url' => 'https://example.com/runbook',
    ]);

    $attachment = $asset->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom.json',
        'payload' => '{"components":[]}',
        'size_bytes' => 20,
        'created_at' => now(),
    ]);

    $asset->delete();

    expect(CuratedLink::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and(Attachment::query()->whereKey($attachment->id)->exists())->toBeFalse();
});

it('stores flexible metadata as an array', function () {
    $asset = SoftwareAsset::factory()->create([
        'metadata' => ['owner_team' => 'Payments', 'criticality' => 'high'],
    ]);

    expect($asset->fresh()->metadata)->toBe(['owner_team' => 'Payments', 'criticality' => 'high']);
});
