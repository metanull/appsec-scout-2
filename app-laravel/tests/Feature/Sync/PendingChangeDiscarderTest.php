<?php

use App\Audit\AuditLog;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PendingChangeDiscarder;

it('drops the pending change and retry bookkeeping, keeping the state last read from the source', function () {
    $actor = User::factory()->create();
    $event = SecurityEvent::factory()->create([
        'state' => EventState::Open,
        'pending_state' => EventState::Dismissed,
        'pending_severity' => EventSeverity::Low,
        'pending_comment' => 'False positive in vendored code.',
        'is_dirty' => true,
        'metadata' => ['pushRetryCount' => 3, 'lastPushError' => '409 Conflict', 'sourceRepoId' => 'repo-1'],
    ]);

    $count = app(PendingChangeDiscarder::class)->discard([$event], $actor);

    $event->refresh();
    $audit = AuditLog::query()->where('action', 'pending_change_discarded')->sole();

    expect($count)->toBe(1)
        ->and($event->state)->toBe(EventState::Open)
        ->and($event->pending_state)->toBeNull()
        ->and($event->pending_severity)->toBeNull()
        ->and($event->pending_comment)->toBeNull()
        ->and($event->is_dirty)->toBeFalse()
        ->and($event->metadata)->toBe(['sourceRepoId' => 'repo-1'])
        ->and($event->comments()->latest('id')->first()?->body)
        ->toBe('[Pending change discarded] The unpushed change (state → dismissed, severity → low) was not pushed to the source; the alert stays "open" as last read from the source.')
        ->and($event->comments()->latest('id')->first()?->author_user_id)->toBe($actor->id)
        ->and($audit->subject_id)->toBe((string) $event->id)
        ->and($audit->payload_json['discarded'])->toMatchArray([
            'pending_state' => 'dismissed',
            'pending_severity' => 'low',
            'pending_comment' => 'False positive in vendored code.',
            'push_retry_count' => 3,
            'last_push_error' => '409 Conflict',
        ]);
});

it('leaves alerts without a pending change untouched', function () {
    $event = SecurityEvent::factory()->create(['is_dirty' => false, 'pending_severity' => EventSeverity::Low]);

    $count = app(PendingChangeDiscarder::class)->discard([$event], User::factory()->create());

    expect($count)->toBe(0)
        ->and($event->fresh()?->pending_severity)->toBe(EventSeverity::Low)
        ->and($event->comments()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'pending_change_discarded')->exists())->toBeFalse();
});
