<?php

use App\Assets\LocalFindingCommentManager;
use App\Audit\AuditLog;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\SecurityContainer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('adds a standalone comment to a local finding and records an audit row', function () {
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    $comment = app(LocalFindingCommentManager::class)->add($finding, $user, 'Verified this is used only in the local dev environment.');

    expect($comment->author_user_id)->toBe($user->id)
        ->and($comment->local_finding_id)->toBe($finding->id)
        ->and(LocalFindingComment::query()->whereKey($comment->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'comment_added')->where('subject_id', (string) $finding->id)->exists())->toBeTrue();
});

it('lists comments chronologically through the relationship', function () {
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    $first = LocalFindingComment::query()->create([
        'local_finding_id' => $finding->id,
        'body' => 'First comment',
        'created_at' => now()->subMinutes(2),
    ]);
    $second = LocalFindingComment::query()->create([
        'local_finding_id' => $finding->id,
        'body' => 'Second comment',
        'created_at' => now()->subMinute(),
    ]);

    expect($finding->fresh()->comments->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('rejects an empty comment', function () {
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    expect(fn () => app(LocalFindingCommentManager::class)->add($finding, $user, '   '))
        ->toThrow(ValidationException::class);
});
