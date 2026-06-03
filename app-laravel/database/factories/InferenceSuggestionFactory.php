<?php

namespace Database\Factories;

use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<InferenceSuggestion>
 */
class InferenceSuggestionFactory extends Factory
{
    protected $model = InferenceSuggestion::class;

    public function definition(): array
    {
        return [
            'suggestion_type' => 'tracker_project_mapping',
            'subject_type' => SoftwareSystem::class,
            'subject_id' => SoftwareSystem::factory(),
            'target_type' => SecurityContainer::class,
            'target_id' => SecurityContainer::factory(),
            'proposed_action' => 'link_tracker_project',
            'confidence' => '0.8500',
            'evidence' => [
                'matched_fields' => ['repository_name', 'project_key'],
                'source' => 'heuristic',
            ],
            'evidence_fingerprint' => sha1((string) fake()->unique()->uuid()),
            'status' => InferenceSuggestionStatus::Pending,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ];
    }

    public function reviewed(
        InferenceSuggestionStatus $status = InferenceSuggestionStatus::Accepted,
        ?User $reviewer = null,
    ): static {
        return $this->state([
            'status' => $status,
            'reviewed_by_user_id' => $reviewer?->id ?? User::factory(),
            'reviewed_at' => now(),
            'review_note' => 'Reviewed in suggestion workflow.',
        ]);
    }

    public function forSubject(Model $model): static
    {
        return $this->state([
            'subject_type' => $model::class,
            'subject_id' => $model->getKey(),
        ]);
    }

    public function forTarget(?Model $model): static
    {
        if ($model === null) {
            return $this->state([
                'target_type' => null,
                'target_id' => null,
            ]);
        }

        return $this->state([
            'target_type' => $model::class,
            'target_id' => $model->getKey(),
        ]);
    }
}
