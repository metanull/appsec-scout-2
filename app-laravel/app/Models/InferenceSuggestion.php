<?php

namespace App\Models;

use App\Models\Enums\InferenceSuggestionStatus;
use Database\Factories\InferenceSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
