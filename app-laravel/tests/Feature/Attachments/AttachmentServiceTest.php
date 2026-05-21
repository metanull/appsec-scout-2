<?php

use App\Audit\AuditLog;
use App\Models\SecurityEvent;
use App\Triage\AttachmentService;

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
