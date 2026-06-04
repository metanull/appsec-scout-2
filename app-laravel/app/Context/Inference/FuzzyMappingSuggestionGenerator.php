<?php

namespace App\Context\Inference;

use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\Enums\RepositoryProviderType;
use App\Models\InferenceSuggestion;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

final class FuzzyMappingSuggestionGenerator
{
    private const TYPE_VIRTUAL_SYSTEM_MEMBERSHIP = 'virtual_system_membership_candidate';

    private const TYPE_VIRTUAL_CONTAINER_MEMBERSHIP = 'virtual_container_membership_candidate';

    private const TYPE_REPOSITORY_MAPPING = 'repository_mapping_candidate';

    private const TYPE_TRACKER_PROJECT_MAPPING = 'tracker_project_mapping_candidate';

    private const ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM = 'add_system_to_virtual_system';

    private const ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER = 'add_container_to_virtual_container';

    private const ACTION_CREATE_REPOSITORY_MAPPING = 'create_repository_mapping';

    private const ACTION_CREATE_TRACKER_PROJECT_LINK = 'create_tracker_project_link';

    /**
     * Confidence is explicit and deterministic per evidence type:
     * - 0.9300 exact project-name/system-group matches
     * - 0.9600 exact repository URL/container-group and repository mapping URL matches
     * - 0.9100 exact tracker project key/repository identifier matches
     */
    public function generate(): int
    {
        $created = 0;

        $created += $this->generateVirtualSystemMembershipSuggestions();
        $created += $this->generateVirtualContainerMembershipSuggestions();
        $created += $this->generateRepositoryMappingSuggestions();
        $created += $this->generateTrackerProjectMappingSuggestions();

        return $created;
    }

    private function generateVirtualSystemMembershipSuggestions(): int
    {
        $created = 0;

        /** @var EloquentCollection<int, SoftwareSystem> $systems */
        $systems = SoftwareSystem::query()->whereNotNull('metadata')->get();

        /** @var array<string, list<SoftwareSystem>> $byProjectName */
        $byProjectName = [];

        foreach ($systems as $system) {
            $metadataRaw = $system->getAttribute('metadata');
            $metadata = is_array($metadataRaw) ? $metadataRaw : [];
            $projectName = SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_PROJECT_NAME);

            if (! is_string($projectName) || trim($projectName) === '') {
                continue;
            }

            $normalized = mb_strtolower(trim($projectName));
            $byProjectName[$normalized] ??= [];
            $byProjectName[$normalized][] = $system;
        }

        foreach ($byProjectName as $projectName => $groupedSystems) {
            if (count($groupedSystems) < 2) {
                continue;
            }

            $systemIds = array_map(static fn (SoftwareSystem $system): int => (int) $system->id, $groupedSystems);

            /** @var EloquentCollection<int, SoftwareSystemLink> $links */
            $links = SoftwareSystemLink::query()
                ->whereHas('members', fn ($query) => $query->whereIn('software_systems.id', $systemIds))
                ->with('members:id')
                ->get();

            foreach ($links as $link) {
                $memberIds = $link->members
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($groupedSystems as $candidate) {
                    if (in_array((int) $candidate->id, $memberIds, true)) {
                        continue;
                    }

                    $evidence = [
                        'reason' => 'Exact AzDO project name match with existing virtual system members.',
                        'matched_key' => SourceContextFacts::AZDO_PROJECT_NAME,
                        'matched_value' => $projectName,
                        'source_system_ids' => $systemIds,
                        'target_link_id' => (int) $link->id,
                    ];

                    if ($this->createPendingSuggestion(
                        suggestionType: self::TYPE_VIRTUAL_SYSTEM_MEMBERSHIP,
                        subject: $candidate,
                        target: $link,
                        proposedAction: self::ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM,
                        confidence: '0.9300',
                        evidence: $evidence,
                    )) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    private function generateVirtualContainerMembershipSuggestions(): int
    {
        $created = 0;

        /** @var EloquentCollection<int, SecurityContainer> $containers */
        $containers = SecurityContainer::query()->whereNotNull('metadata')->get();

        /** @var array<string, list<SecurityContainer>> $byRemoteUrl */
        $byRemoteUrl = [];

        foreach ($containers as $container) {
            $metadataRaw = $container->getAttribute('metadata');
            $metadata = is_array($metadataRaw) ? $metadataRaw : [];
            $remoteUrl = SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL)
                ?? SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_WEB_URL);

            if (! is_string($remoteUrl) || trim($remoteUrl) === '') {
                continue;
            }

            $normalized = mb_strtolower(trim($remoteUrl));
            $byRemoteUrl[$normalized] ??= [];
            $byRemoteUrl[$normalized][] = $container;
        }

        foreach ($byRemoteUrl as $remoteUrl => $groupedContainers) {
            if (count($groupedContainers) < 2) {
                continue;
            }

            $containerIds = array_map(static fn (SecurityContainer $container): int => (int) $container->id, $groupedContainers);

            /** @var EloquentCollection<int, SecurityContainerLink> $links */
            $links = SecurityContainerLink::query()
                ->whereHas('members', fn ($query) => $query->whereIn('security_containers.id', $containerIds))
                ->with('members:id')
                ->get();

            foreach ($links as $link) {
                $memberIds = $link->members
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($groupedContainers as $candidate) {
                    if (in_array((int) $candidate->id, $memberIds, true)) {
                        continue;
                    }

                    $evidence = [
                        'reason' => 'Exact repository URL match with existing virtual container members.',
                        'matched_key' => SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL,
                        'matched_value' => $remoteUrl,
                        'source_container_ids' => $containerIds,
                        'target_link_id' => (int) $link->id,
                    ];

                    if ($this->createPendingSuggestion(
                        suggestionType: self::TYPE_VIRTUAL_CONTAINER_MEMBERSHIP,
                        subject: $candidate,
                        target: $link,
                        proposedAction: self::ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER,
                        confidence: '0.9600',
                        evidence: $evidence,
                    )) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    private function generateRepositoryMappingSuggestions(): int
    {
        $created = 0;

        $owners = $this->ownersWithMetadata();

        foreach ($owners as $owner) {
            if ($owner->repositoryMappings()->exists()) {
                continue;
            }

            $metadataRaw = $owner->getAttribute('metadata');
            $metadata = is_array($metadataRaw) ? $metadataRaw : [];

            $repositoryUrl = SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_WEB_URL)
                ?? $this->githubRepositoryUrl(SourceContextFacts::getString($metadata, SourceContextFacts::TRACKER_GITHUB_REPOSITORY));

            if (! is_string($repositoryUrl) || trim($repositoryUrl) === '') {
                continue;
            }

            $provider = $this->findProviderForRepositoryUrl($repositoryUrl);

            if (! $provider instanceof RepositoryProvider) {
                continue;
            }

            $repositoryName = $this->repositoryNameFromUrl($provider, $repositoryUrl);

            if ($repositoryName === null) {
                continue;
            }

            $evidence = [
                'reason' => 'Exact repository URL indicates a concrete provider and repository mapping candidate.',
                'matched_key' => SourceContextFacts::AZDO_REPOSITORY_WEB_URL,
                'matched_value' => $repositoryUrl,
                'repository_name' => $repositoryName,
                'repository_provider_id' => (int) $provider->id,
            ];

            if ($this->createPendingSuggestion(
                suggestionType: self::TYPE_REPOSITORY_MAPPING,
                subject: $owner,
                target: $provider,
                proposedAction: self::ACTION_CREATE_REPOSITORY_MAPPING,
                confidence: '0.9600',
                evidence: $evidence,
            )) {
                $created++;
            }
        }

        return $created;
    }

    private function generateTrackerProjectMappingSuggestions(): int
    {
        $created = 0;

        $owners = $this->ownersWithMetadata();

        foreach ($owners as $owner) {
            $metadataRaw = $owner->getAttribute('metadata');
            $metadata = is_array($metadataRaw) ? $metadataRaw : [];

            $jiraProjectKey = SourceContextFacts::getString($metadata, SourceContextFacts::TRACKER_JIRA_PROJECT_KEY);

            if (is_string($jiraProjectKey) && trim($jiraProjectKey) !== '') {
                if (! $owner->trackerProjectLinks()->where('tracker_id', 'jira')->where('project_key', $jiraProjectKey)->exists()) {
                    $evidence = [
                        'reason' => 'Exact Jira project key in context metadata suggests a tracker mapping.',
                        'matched_key' => SourceContextFacts::TRACKER_JIRA_PROJECT_KEY,
                        'matched_value' => $jiraProjectKey,
                        'tracker_id' => 'jira',
                    ];

                    if ($this->createPendingSuggestion(
                        suggestionType: self::TYPE_TRACKER_PROJECT_MAPPING,
                        subject: $owner,
                        target: null,
                        proposedAction: self::ACTION_CREATE_TRACKER_PROJECT_LINK,
                        confidence: '0.9100',
                        evidence: $evidence,
                    )) {
                        $created++;
                    }
                }
            }

            $githubRepository = SourceContextFacts::getString($metadata, SourceContextFacts::TRACKER_GITHUB_REPOSITORY);

            if (! is_string($githubRepository) || trim($githubRepository) === '') {
                continue;
            }

            if ($owner->trackerProjectLinks()->where('tracker_id', 'github')->where('project_key', $githubRepository)->exists()) {
                continue;
            }

            $evidence = [
                'reason' => 'Exact GitHub repository key in context metadata suggests a tracker mapping.',
                'matched_key' => SourceContextFacts::TRACKER_GITHUB_REPOSITORY,
                'matched_value' => $githubRepository,
                'tracker_id' => 'github',
            ];

            if ($this->createPendingSuggestion(
                suggestionType: self::TYPE_TRACKER_PROJECT_MAPPING,
                subject: $owner,
                target: null,
                proposedAction: self::ACTION_CREATE_TRACKER_PROJECT_LINK,
                confidence: '0.9100',
                evidence: $evidence,
            )) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function createPendingSuggestion(
        string $suggestionType,
        Model $subject,
        ?Model $target,
        string $proposedAction,
        string $confidence,
        array $evidence,
    ): bool {
        $fingerprint = $this->fingerprint($suggestionType, $subject, $target, $proposedAction, $evidence);

        if (InferenceSuggestion::query()
            ->where('suggestion_type', $suggestionType)
            ->where('evidence_fingerprint', $fingerprint)
            ->where('status', InferenceSuggestionStatus::Rejected)
            ->exists()) {
            return false;
        }

        if (InferenceSuggestion::query()
            ->where('suggestion_type', $suggestionType)
            ->where('evidence_fingerprint', $fingerprint)
            ->exists()) {
            return false;
        }

        InferenceSuggestion::query()->create([
            'suggestion_type' => $suggestionType,
            'subject_type' => $subject::class,
            'subject_id' => (int) $subject->getKey(),
            'target_type' => $target ? $target::class : null,
            'target_id' => $target ? (int) $target->getKey() : null,
            'proposed_action' => $proposedAction,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'evidence_fingerprint' => $fingerprint,
            'status' => InferenceSuggestionStatus::Pending,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function fingerprint(
        string $suggestionType,
        Model $subject,
        ?Model $target,
        string $proposedAction,
        array $evidence,
    ): string {
        $payload = [
            'suggestion_type' => $suggestionType,
            'subject_type' => $subject::class,
            'subject_id' => (int) $subject->getKey(),
            'target_type' => $target ? $target::class : null,
            'target_id' => $target ? (int) $target->getKey() : null,
            'proposed_action' => $proposedAction,
            'evidence' => $this->normalizeForFingerprint($evidence),
        ];

        return sha1((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeForFingerprint(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return [];
        }

        if ($this->isSequentialArray($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForFingerprint($item), $value);
        }

        ksort($value);

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $this->normalizeForFingerprint($item);
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private function isSequentialArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function githubRepositoryUrl(?string $repository): ?string
    {
        if (! is_string($repository) || trim($repository) === '') {
            return null;
        }

        return 'https://github.com/' . trim($repository, '/');
    }

    private function findProviderForRepositoryUrl(string $repositoryUrl): ?RepositoryProvider
    {
        $urlHost = parse_url($repositoryUrl, PHP_URL_HOST);

        if (! is_string($urlHost) || trim($urlHost) === '') {
            return null;
        }

        /** @var EloquentCollection<int, RepositoryProvider> $providers */
        $providers = RepositoryProvider::query()->get();

        foreach ($providers as $provider) {
            $providerType = RepositoryProviderType::from((string) $provider->getRawOriginal('provider_type'));

            $providerHost = parse_url($provider->base_url, PHP_URL_HOST);

            if (! is_string($providerHost) || trim($providerHost) === '') {
                continue;
            }

            if (mb_strtolower($providerHost) !== mb_strtolower($urlHost)) {
                continue;
            }

            if ($providerType === RepositoryProviderType::AzureRepos && str_contains($repositoryUrl, '/_git/')) {
                return $provider;
            }

            if ($providerType === RepositoryProviderType::GitHub) {
                return $provider;
            }
        }

        return null;
    }

    private function repositoryNameFromUrl(RepositoryProvider $provider, string $repositoryUrl): ?string
    {
        $providerType = RepositoryProviderType::from((string) $provider->getRawOriginal('provider_type'));

        $path = parse_url($repositoryUrl, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        if ($providerType === RepositoryProviderType::AzureRepos) {
            $gitIndex = array_search('_git', $segments, true);

            if (! is_int($gitIndex) || ! isset($segments[$gitIndex + 1])) {
                return null;
            }

            return $segments[$gitIndex + 1];
        }

        if (count($segments) < 2) {
            return null;
        }

        return $segments[0] . '/' . $segments[1];
    }

    /** @return list<SoftwareSystem|SecurityContainer> */
    private function ownersWithMetadata(): array
    {
        return array_merge(
            SoftwareSystem::query()->whereNotNull('metadata')->get()->all(),
            SecurityContainer::query()->whereNotNull('metadata')->get()->all(),
        );
    }
}
