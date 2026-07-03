<?php

use App\Audit\AuditLog;
use App\Models\SecurityEvent;
use App\Triage\AttachmentService;

it('stores payloads larger than a MySQL BLOB (64 KiB) without truncation', function () {
    $event = SecurityEvent::factory()->create();
    $service = app(AttachmentService::class);

    $payload = str_repeat('a', 200_000);

    $attachment = $service->attachToEvent(
        event: $event,
        kind: 'codesearch-json',
        mime: 'application/json',
        name: 'large-result.json',
        payload: $payload,
        createdByCommand: 'triage:codesearch',
    );

    expect($attachment->fresh()->payload)->toBe($payload)
        ->and($attachment->size_bytes)->toBe(200_000);
});

it('records an audit row when deleting an attachment', function () {
    $event = SecurityEvent::factory()->create();
    $service = app(AttachmentService::class);

    $attachment = $service->attachToEvent(
        event: $event,
        kind: 'codesearch-json',
        mime: 'application/json',
        name: 'result.json',
        payload: '{"count":1}',
        createdByCommand: 'triage:codesearch',
    );

    $service->delete($attachment);

    expect($event->attachments()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'attachment_deleted')->exists())->toBeTrue();
});
