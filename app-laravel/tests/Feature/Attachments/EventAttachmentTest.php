<?php

use App\Models\EventAttachment;
use App\Models\SecurityEvent;

it('stores binary payloads on event attachments', function () {
    $event = SecurityEvent::factory()->create();
    $payload = '{"runs":[]}';

    $attachment = EventAttachment::query()->create([
        'event_id' => $event->id,
        'kind' => 'codesearch-json',
        'mime' => 'application/json',
        'name' => 'codesearch-20260521.json',
        'payload' => $payload,
        'size_bytes' => strlen($payload),
        'created_at' => now(),
        'created_by_command' => 'triage:codesearch',
    ]);

    expect($attachment->payload)->toBe($payload)
        ->and($attachment->size_bytes)->toBe(strlen($payload))
        ->and($event->attachments()->first()?->is($attachment))->toBeTrue();
});
