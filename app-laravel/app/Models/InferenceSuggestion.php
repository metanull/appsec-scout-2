<?php

namespace App\Models;

use App\Models\Enums\InferenceSuggestionStatus;
use Database\Factories\InferenceSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'suggestion_type',
    'subject_type',
    'subject_id',
    'target_type',
    'target_id',
    'proposed_action',
    'confidence',
    'evidence',
    'evidence_fingerprint',
    'status',
    'reviewed_by_user_id',
    'reviewed_at',
    'review_note',
])]
class InferenceSuggestion extends Model
{
    /** @use HasFactory<InferenceSuggestionFactory> */
    use HasFactory;

    public const TYPE_VIRTUAL_SYSTEM_MEMBERSHIP = 'virtual_system_membership_candidate';

    public const TYPE_VIRTUAL_CONTAINER_MEMBERSHIP = 'virtual_container_membership_candidate';

    public const TYPE_REPOSITORY_MAPPING = 'repository_mapping_candidate';

    public const TYPE_TRACKER_PROJECT_MAPPING = 'tracker_project_mapping_candidate';

    public const ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM = 'add_system_to_virtual_system';

    public const ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER = 'add_container_to_virtual_container';

    public const ACTION_CREATE_REPOSITORY_MAPPING = 'create_repository_mapping';

    public const ACTION_CREATE_TRACKER_PROJECT_LINK = 'create_tracker_project_link';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'decimal:4',
            'status' => InferenceSuggestionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Order pending suggestions before non-pending, then newest first.
     *
     * @param  Builder<InferenceSuggestion>  $query
     * @return Builder<InferenceSuggestion>
     */
    public function scopePendingFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');
    }
}
