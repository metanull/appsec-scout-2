<?php

use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates pending and reviewed inference suggestions with casts and relations', function () {
    $subject = SoftwareSystem::factory()->create();
    $target = SecurityContainer::factory()->forSystem($subject)->create();
    $reviewer = User::factory()->create();

    $pending = InferenceSuggestion::factory()
        ->forSubject($subject)
        ->forTarget($target)
        ->create();

    $reviewed = InferenceSuggestion::factory()
        ->forSubject($subject)
        ->forTarget($target)
        ->reviewed(InferenceSuggestionStatus::Accepted, $reviewer)
        ->create();

    expect($pending->status)->toBe(InferenceSuggestionStatus::Pending)
        ->and($pending->evidence)->toBeArray()
        ->and((string) $pending->confidence)->toBe('0.8500')
        ->and($pending->subject?->is($subject))->toBeTrue()
        ->and($pending->target?->is($target))->toBeTrue()
        ->and($pending->reviewedBy)->toBeNull();

    expect($reviewed->status)->toBe(InferenceSuggestionStatus::Accepted)
        ->and($reviewed->reviewed_at)->not->toBeNull()
        ->and($reviewed->reviewedBy?->is($reviewer))->toBeTrue();
});

it('prevents duplicate pending suggestions for the same type and evidence fingerprint', function () {
    $subject = SoftwareSystem::factory()->create();

    InferenceSuggestion::factory()
        ->forSubject($subject)
        ->forTarget(null)
        ->create([
            'suggestion_type' => 'repository_mapping',
            'evidence_fingerprint' => 'fp-123',
            'status' => InferenceSuggestionStatus::Pending,
        ]);

    expect(fn () => InferenceSuggestion::factory()
        ->forSubject($subject)
        ->forTarget(null)
        ->create([
            'suggestion_type' => 'repository_mapping',
            'evidence_fingerprint' => 'fp-123',
            'status' => InferenceSuggestionStatus::Pending,
        ]))->toThrow(QueryException::class);
});

it('allows the same evidence fingerprint after review status changes', function () {
    $subject = SoftwareSystem::factory()->create();

    InferenceSuggestion::factory()
        ->forSubject($subject)
        ->forTarget(null)
        ->create([
            'suggestion_type' => 'repository_mapping',
            'evidence_fingerprint' => 'fp-456',
            'status' => InferenceSuggestionStatus::Pending,
        ]);

    $accepted = InferenceSuggestion::factory()
        ->forSubject($subject)
        ->forTarget(null)
        ->reviewed(InferenceSuggestionStatus::Accepted)
        ->create([
            'suggestion_type' => 'repository_mapping',
            'evidence_fingerprint' => 'fp-456',
        ]);

    expect($accepted->status)->toBe(InferenceSuggestionStatus::Accepted);
});

it('rejects unsupported status values', function () {
    $this->expectException(ValueError::class);

    InferenceSuggestion::query()->create([
        'suggestion_type' => 'repository_mapping',
        'subject_type' => SoftwareSystem::class,
        'subject_id' => SoftwareSystem::factory()->create()->id,
        'target_type' => null,
        'target_id' => null,
        'proposed_action' => 'link_repository',
        'confidence' => '0.9000',
        'evidence' => ['source' => 'heuristic'],
        'evidence_fingerprint' => 'fp-invalid-status',
        'status' => 'invalid-status',
        'reviewed_by_user_id' => null,
        'reviewed_at' => null,
        'review_note' => null,
    ]);
});
