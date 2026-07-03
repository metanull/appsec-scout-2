<?php

use App\Assets\AttachmentService;
use App\Audit\AuditLog;
use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;

it('attaches files to a software asset, a software system, and a security container', function () {
    $service = app(AttachmentService::class);

    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $assetAttachment = $service->attachTo($asset, 'sbom', 'application/json', 'asset-sbom.json', '{"components":[]}');
    $systemAttachment = $service->attachTo($system, 'pipeline-run', 'application/json', 'run.json', '{"status":"ok"}');
    $containerAttachment = $service->attachTo($container, 'dependency-report', 'text/plain', 'outdated.txt', 'lodash 4.17.19 -> 4.17.21');

    expect($assetAttachment->owner_type)->toBe(SoftwareAsset::class)
        ->and($assetAttachment->owner_id)->toBe($asset->id)
        ->and($systemAttachment->owner_type)->toBe(SoftwareSystem::class)
        ->and($containerAttachment->owner_type)->toBe(SecurityContainer::class)
        ->and($containerAttachment->size_bytes)->toBe(strlen('lodash 4.17.19 -> 4.17.21'))
        ->and(AuditLog::query()->where('action', 'attachment_created')->count())->toBe(3);
});

it('stores payloads larger than a MySQL BLOB (64 KiB) without truncation', function () {
    $service = app(AttachmentService::class);
    $asset = SoftwareAsset::factory()->create();

    $payload = str_repeat('a', 200_000);

    $attachment = $service->attachTo($asset, 'sbom', 'application/json', 'large.cdx.json', $payload);

    expect($attachment->fresh()->payload)->toBe($payload)
        ->and($attachment->size_bytes)->toBe(200_000);
});

it('records an audit row when deleting an attachment', function () {
    $service = app(AttachmentService::class);
    $asset = SoftwareAsset::factory()->create();

    $attachment = $service->attachTo($asset, 'sbom', 'application/json', 'sbom.json', '{}');

    $service->delete($attachment);

    expect(Attachment::query()->whereKey($attachment->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'attachment_deleted')->exists())->toBeTrue();
});
