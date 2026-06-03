<?php

namespace App\Context\Inference;

use App\Audit\Recorder;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InferenceSuggestionReviewService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly InferenceSuggestionApplier $applier,
    ) {}

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    public function accept(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput = []): InferenceSuggestion
    {
        return $this->applier->accept($suggestion, $reviewer, $acceptedInput);
    }

    public function reject(InferenceSuggestion $suggestion, User $reviewer, string $reviewNote): InferenceSuggestion
    {
        $this->assertReviewer($reviewer);
        $this->assertPending($suggestion);

        $trimmedReviewNote = trim($reviewNote);

        if ($trimmedReviewNote === '') {
            throw ValidationException::withMessages([
                'review_note' => 'A review note is required when rejecting a suggestion.',
            ]);
        }

        return DB::transaction(function () use ($suggestion, $reviewer, $trimmedReviewNote): InferenceSuggestion {
            $suggestion->forceFill([
                'status' => InferenceSuggestionStatus::Rejected,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $trimmedReviewNote,
            ])->save();

            $this->recorder->recordAdminAction('inference_suggestion_rejected', [
                'inference_suggestion_id' => $suggestion->id,
                'suggestion_type' => $suggestion->suggestion_type,
                'proposed_action' => $suggestion->proposed_action,
                'subject_type' => $suggestion->subject_type,
                'subject_id' => $suggestion->subject_id,
                'target_type' => $suggestion->target_type,
                'target_id' => $suggestion->target_id,
                'review_note' => $trimmedReviewNote,
            ]);

            return $suggestion->refresh();
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->hasAnyRole(['Plan', 'Admin'])) {
            throw new AuthorizationException('Only Plan/Admin users can review inference suggestions.');
        }
    }

    private function assertPending(InferenceSuggestion $suggestion): void
    {
        $status = InferenceSuggestionStatus::from((string) $suggestion->getRawOriginal('status'));

        if ($status !== InferenceSuggestionStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending suggestions can be reviewed.',
            ]);
        }
    }
}
