<?php

namespace App\Context\Inference;

use App\Audit\Recorder;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\SourceCode\RepositoryMappingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InferenceSuggestionApplier
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly RepositoryMappingService $repositoryMappingService,
    ) {}

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    public function accept(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput = []): InferenceSuggestion
    {
        $this->assertReviewerCanDecide($reviewer);
        $this->assertPending($suggestion);

        return DB::transaction(function () use ($suggestion, $reviewer, $acceptedInput): InferenceSuggestion {
            $this->applySuggestion($suggestion, $reviewer, $acceptedInput);

            $suggestion->forceFill([
                'status' => InferenceSuggestionStatus::Accepted,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => null,
            ])->save();

            $this->recorder->recordAdminAction('inference_suggestion_accepted', [
                'inference_suggestion_id' => $suggestion->id,
                'suggestion_type' => $suggestion->suggestion_type,
                'proposed_action' => $suggestion->proposed_action,
                'reviewed_by_user_id' => $reviewer->id,
            ]);

            return $suggestion->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function applySuggestion(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput): void
    {
        match ($suggestion->proposed_action) {
            InferenceSuggestion::ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM => $this->applySystemMembership($suggestion, $reviewer, $acceptedInput),
            InferenceSuggestion::ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER => $this->applyContainerMembership($suggestion, $reviewer, $acceptedInput),
            InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING => $this->applyRepositoryMapping($suggestion, $reviewer, $acceptedInput),
            InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK => $this->applyTrackerProjectLink($suggestion, $reviewer, $acceptedInput),
            default => throw ValidationException::withMessages([
                'proposed_action' => 'The suggested action is not supported.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function applySystemMembership(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput): void
    {
        $system = $this->resolveSubject($suggestion, SoftwareSystem::class);
        $link = $this->resolveSystemLink($suggestion, $acceptedInput);

        if ($link->members()->whereKey($system->getKey())->exists()) {
            return;
        }

        $sortOrder = (int) ($link->members()->max('software_system_link_members.sort_order') ?? 0) + 1;

        $link->members()->attach($system->getKey(), ['sort_order' => $sortOrder]);

        $this->recorder->recordAdminAction('software_system_link_member_added', [
            'software_system_link_id' => $link->id,
            'software_system_id' => $system->id,
            'sort_order' => $sortOrder,
            'reviewed_by_user_id' => $reviewer->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function applyContainerMembership(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput): void
    {
        $container = $this->resolveContainer($suggestion);
        $link = $this->resolveContainerLink($suggestion, $acceptedInput);

        if ($link->members()->whereKey($container->getKey())->exists()) {
            return;
        }

        $sortOrder = (int) ($link->members()->max('security_container_link_members.sort_order') ?? 0) + 1;

        $link->addMember($container, $sortOrder);

        $this->recorder->recordAdminAction('inference_suggestion_container_membership_applied', [
            'inference_suggestion_id' => $suggestion->id,
            'security_container_link_id' => $link->id,
            'security_container_id' => $container->id,
            'reviewed_by_user_id' => $reviewer->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function applyRepositoryMapping(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput): void
    {
        $owner = $this->resolveOwner($suggestion);
        $providerId = $this->integerFrom(
            $acceptedInput['repository_provider_id'] ?? null,
            $this->evidenceValue($suggestion, 'repository_provider_id'),
            'repository_provider_id',
        );
        $repositoryName = $this->textFrom(
            $acceptedInput['repository_name'] ?? null,
            $this->evidenceValue($suggestion, 'repository_name'),
            'repository_name',
        );
        $defaultBranch = $this->textOrDefault(
            $acceptedInput['default_branch'] ?? null,
            $this->evidenceValue($suggestion, 'default_branch'),
            'main',
        );
        $pathPrefix = $this->nullableText(
            $acceptedInput['path_prefix'] ?? null,
            $this->evidenceValue($suggestion, 'path_prefix'),
        );

        $provider = RepositoryProvider::query()->find($providerId);

        if (! $provider instanceof RepositoryProvider) {
            throw ValidationException::withMessages([
                'repository_provider_id' => 'The selected repository provider is invalid.',
            ]);
        }

        $existing = $owner->repositoryMappings()
            ->where('repository_provider_id', $provider->id)
            ->where('repository_name', $repositoryName)
            ->first();

        $data = [
            'repository_provider_id' => $provider->id,
            'repository_name' => $repositoryName,
            'default_branch' => $defaultBranch,
            'path_prefix' => $pathPrefix,
        ];

        if ($existing instanceof RepositoryMapping) {
            $this->repositoryMappingService->update($existing, $data);
        } else {
            $this->repositoryMappingService->create($owner, $reviewer, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function applyTrackerProjectLink(InferenceSuggestion $suggestion, User $reviewer, array $acceptedInput): void
    {
        $owner = $this->resolveOwner($suggestion);
        $trackerId = $this->textFrom(
            $acceptedInput['tracker_id'] ?? null,
            $this->evidenceValue($suggestion, 'tracker_id'),
            'tracker_id',
        );
        $projectKey = $this->textFrom(
            $acceptedInput['project_key'] ?? null,
            $this->evidenceValue($suggestion, 'matched_value'),
            'project_key',
        );
        $projectName = $this->nullableText(
            $acceptedInput['project_name'] ?? null,
            $this->evidenceValue($suggestion, 'project_name'),
        );

        $existing = $owner->trackerProjectLinks()
            ->where('tracker_id', $trackerId)
            ->where('project_key', $projectKey)
            ->first();

        if ($existing instanceof TrackerProjectLink) {
            $updates = [];

            if ($projectName !== null && $existing->project_name !== $projectName) {
                $updates['project_name'] = $projectName;
            }

            if ($updates !== []) {
                $existing->forceFill($updates)->save();
            }

            return;
        }

        $owner->trackerProjectLinks()->create([
            'tracker_id' => $trackerId,
            'project_key' => $projectKey,
            'project_name' => $projectName,
            'is_default' => false,
            'created_by_user_id' => $reviewer->id,
            'metadata' => [
                'inference_suggestion_id' => $suggestion->id,
                'source' => 'inference',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function resolveSystemLink(InferenceSuggestion $suggestion, array $acceptedInput): SoftwareSystemLink
    {
        $targetId = $this->integerFrom(
            $acceptedInput['target_link_id'] ?? null,
            $suggestion->target_id,
            'target_link_id',
        );

        $link = SoftwareSystemLink::query()->find($targetId);

        if (! $link instanceof SoftwareSystemLink) {
            throw ValidationException::withMessages([
                'target_link_id' => 'The selected virtual system link is invalid.',
            ]);
        }

        return $link;
    }

    /**
     * @param  array<string, mixed>  $acceptedInput
     */
    private function resolveContainerLink(InferenceSuggestion $suggestion, array $acceptedInput): SecurityContainerLink
    {
        $targetId = $this->integerFrom(
            $acceptedInput['target_link_id'] ?? null,
            $suggestion->target_id,
            'target_link_id',
        );

        $link = SecurityContainerLink::query()->find($targetId);

        if (! $link instanceof SecurityContainerLink) {
            throw ValidationException::withMessages([
                'target_link_id' => 'The selected virtual container link is invalid.',
            ]);
        }

        return $link;
    }

    private function resolveSubject(InferenceSuggestion $suggestion, string $expectedClass): SoftwareSystem|SecurityContainer
    {
        $subject = $suggestion->subject;

        if ($expectedClass === SoftwareSystem::class && $subject instanceof SoftwareSystem) {
            return $subject;
        }

        if ($expectedClass === SecurityContainer::class && $subject instanceof SecurityContainer) {
            return $subject;
        }

        throw ValidationException::withMessages([
            'subject' => 'The suggestion subject is invalid for the selected action.',
        ]);
    }

    private function resolveContainer(InferenceSuggestion $suggestion): SecurityContainer
    {
        $container = $this->resolveSubject($suggestion, SecurityContainer::class);

        if (! $container instanceof SecurityContainer) {
            throw ValidationException::withMessages([
                'subject' => 'The suggestion subject is invalid for the selected action.',
            ]);
        }

        return $container;
    }

    private function resolveOwner(InferenceSuggestion $suggestion): SoftwareSystem|SecurityContainer
    {
        $subject = $suggestion->subject;

        if ($subject instanceof SoftwareSystem || $subject instanceof SecurityContainer) {
            return $subject;
        }

        throw ValidationException::withMessages([
            'subject' => 'The suggestion subject is invalid for the selected action.',
        ]);
    }

    private function evidenceValue(InferenceSuggestion $suggestion, string $key): mixed
    {
        $evidenceRaw = $suggestion->getAttribute('evidence');
        $evidence = is_array($evidenceRaw) ? $evidenceRaw : [];

        return $evidence[$key] ?? null;
    }

    private function integerFrom(mixed $primary, mixed $fallback, string $field): int
    {
        foreach ([$primary, $fallback] as $value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        throw ValidationException::withMessages([
            $field => 'A valid integer value is required.',
        ]);
    }

    private function textFrom(mixed $primary, mixed $fallback, string $field): string
    {
        foreach ([$primary, $fallback] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        throw ValidationException::withMessages([
            $field => 'A value is required.',
        ]);
    }

    private function textOrDefault(mixed $primary, mixed $fallback, string $default): string
    {
        foreach ([$primary, $fallback] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    private function nullableText(mixed $primary, mixed $fallback): ?string
    {
        foreach ([$primary, $fallback] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function assertReviewerCanDecide(User $reviewer): void
    {
        if (! $reviewer->hasAnyRole(['Plan', 'Admin'])) {
            throw new AuthorizationException('Only Plan/Admin users can review inference suggestions.');
        }
    }

    private function assertPending(InferenceSuggestion $suggestion): void
    {
        if (InferenceSuggestionStatus::from((string) $suggestion->getRawOriginal('status')) !== InferenceSuggestionStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending suggestions can be reviewed.',
            ]);
        }
    }
}
