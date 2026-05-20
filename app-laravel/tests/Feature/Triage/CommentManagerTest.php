<?php

use App\Audit\AuditLog;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\CommentManager;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates a local comment marks the event dirty and records an audit row', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Triage']);
    $event = SecurityEvent::factory()->create(['is_dirty' => false]);

    $this->actingAs($user);

    $comment = app(CommentManager::class)->add($event, $user, 'Investigated and kept for sync review.');

    expect($comment->author_user_id)->toBe($user->id)
        ->and($comment->event_id)->toBe($event->id)
        ->and($event->fresh()->is_dirty)->toBeTrue()
        ->and(EventComment::query()->whereKey($comment->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'comment_added')->where('subject_id', (string) $event->id)->exists())->toBeTrue();
});

it('lists comments chronologically through the relationship', function () {
    $event = SecurityEvent::factory()->create();

    $first = EventComment::factory()->for($event, 'event')->create([
        'body' => 'First upstream comment',
        'created_at' => now()->subMinutes(2),
        'upstream_comment_id' => 'upstream-1',
    ]);
    $second = EventComment::factory()->for($event, 'event')->create([
        'body' => 'Second local comment',
        'created_at' => now()->subMinute(),
    ]);

    expect($event->fresh()->comments->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('allows the author to edit a local comment for five minutes only', function () {
    CarbonImmutable::setTestNow('2026-05-21 10:00:00');

    $user = User::factory()->create();
    $event = SecurityEvent::factory()->create();
    $comment = EventComment::factory()->for($event, 'event')->create([
        'author_user_id' => $user->id,
        'body' => 'Initial draft',
        'created_at' => now()->subMinutes(4),
    ]);

    $manager = app(CommentManager::class);

    $updated = $manager->update($comment, $user, 'Updated within the edit window.');

    expect($updated->body)->toBe('Updated within the edit window.');

    $expired = EventComment::factory()->for($event, 'event')->create([
        'author_user_id' => $user->id,
        'body' => 'Expired draft',
        'created_at' => now()->subMinutes(6),
    ]);

    expect(fn () => $manager->update($expired, $user, 'This edit should be blocked.'))
        ->toThrow(ValidationException::class);

    CarbonImmutable::setTestNow();
});
