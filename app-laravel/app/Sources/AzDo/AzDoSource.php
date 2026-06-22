<?php

namespace App\Sources\AzDo;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Sources\Contracts\QueuesEnrichmentJobs;
use App\Sources\Contracts\Source;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use App\Sync\EnrichAzDoSecretJob;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

final class AzDoSource implements QueuesEnrichmentJobs, Source
{
    private ?AzDoClient $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return AzDoNormalizer::SOURCE_ID;
    }

    public function displayName(): string
    {
        return 'Azure DevOps Advanced Security';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            hasContainers: true,
            canUpdateState: true,
            canUpdateSeverity: false,
            canAddComments: true,
            supportedEventTypes: [
                EventType::Vulnerability,
                EventType::CodeQuality,
                EventType::Dependency,
                EventType::Secret,
                EventType::License,
            ],
        );
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'azdo.pat', label: 'Personal Access Token', isSecret: true, required: true, description: 'The Azure DevOps personal access token.'),
            new CredentialField(key: 'azdo.organization', label: 'Organization', isSecret: false, required: true, description: 'The Azure DevOps organization name.'),
        ];
    }

    public function testConnection(): TestResult
    {
        try {
            $client = $this->getClient();

            return $client->testConnection() ? TestResult::success() : TestResult::failure('Connection refused');
        } catch (\Throwable $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    /** @return iterable<SystemDto> */
    public function fetchSystems(): iterable
    {
        $client = $this->getClient();

        foreach ($client->listProjects() as $project) {
            yield AzDoNormalizer::toSystem($project);
        }
    }

    /** @return iterable<ContainerDto> */
    public function fetchContainers(SystemDto $system): iterable
    {
        $client = $this->getClient();

        foreach ($client->listRepositories($system->sourceSystemId) as $repo) {
            $dto = AzDoNormalizer::toContainer($repo);
            yield new ContainerDto(
                sourceContainerId: $dto->sourceContainerId,
                name: $dto->name,
                sourceSystemId: $system->sourceSystemId,
                kind: $dto->kind,
                url: $dto->url,
                metadata: $dto->metadata,
            );
        }
    }

    /** @return iterable<EventDto> */
    public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable
    {
        $client = $this->getClient();
        $sinceDate = $since !== null ? $since->toDateTime() : null;
        $alertTypes = ['code', 'dependency', 'secret', 'license'];

        if ($system !== null) {
            $repos = $client->listRepositories($system->sourceSystemId);
            $project = new AzDoProject(id: $system->sourceSystemId, name: $system->name);

            foreach ($repos as $repo) {
                foreach ($alertTypes as $alertType) {
                    foreach ($client->listAlerts($system->sourceSystemId, $repo->id, $alertType, $sinceDate) as $alert) {
                        yield $this->buildEventDto($alert, $system->sourceSystemId, $repo->id, $project, $repo);
                    }
                }
            }

            return;
        }

        foreach ($client->listProjects() as $project) {
            $repos = $client->listRepositories($project->id);

            foreach ($repos as $repo) {
                foreach ($alertTypes as $alertType) {
                    foreach ($client->listAlerts($project->id, $repo->id, $alertType, $sinceDate) as $alert) {
                        yield $this->buildEventDto($alert, $project->id, $repo->id, $project, $repo);
                    }
                }
            }
        }
    }

    public function pushEventState(SecurityEvent $event): PushResult
    {
        $client = $this->getClient();
        $metadata = self::metadataArray($event);

        $projectId = $metadata['sourceProjectId'] ?? null;
        $repoId = $metadata['sourceRepoId'] ?? null;
        $alertId = (int) $event->source_event_id;

        if (! is_string($projectId) || ! is_string($repoId) || $alertId === 0) {
            return PushResult::failure('Missing project/repo mapping in event metadata');
        }

        $dismissalReason = $metadata['dismissalReason'] ?? null;
        $targetState = self::resolveState($event->pending_state, $event->state);
        $update = AzDoNormalizer::mapStateToSource(
            $targetState,
            is_string($dismissalReason) ? $dismissalReason : null,
            $event->pending_comment,
        );

        try {
            $client->updateAlert($projectId, $repoId, $alertId, $update);

            return PushResult::success();
        } catch (\Throwable $e) {
            return PushResult::failure($e->getMessage());
        }
    }

    public function fetchRawEvent(SecurityEvent $event): EventDto
    {
        $client = $this->getClient();
        $metadata = self::metadataArray($event);

        $projectId = is_string($metadata['sourceProjectId'] ?? null) ? $metadata['sourceProjectId'] : '';
        $repoId = is_string($metadata['sourceRepoId'] ?? null) ? $metadata['sourceRepoId'] : '';
        $alertId = (int) $event->source_event_id;

        $alert = $client->getAlert($projectId, $repoId, $alertId);

        return $this->buildEventDto($alert, $projectId, $repoId);
    }

    public function enrichEvent(SecurityEvent $event): ?EventDto
    {
        if (self::resolveType($event->type) !== EventType::Secret) {
            return null;
        }

        $client = $this->getClient();
        $metadata = self::metadataArray($event);

        $projectId = $metadata['sourceProjectId'] ?? null;
        $repoId = $metadata['sourceRepoId'] ?? null;
        $alertId = (int) $event->source_event_id;

        if (! is_string($projectId) || ! is_string($repoId) || $alertId === 0) {
            return null;
        }

        $instances = $client->getAlertInstances($projectId, $repoId, $alertId);

        $existingMeta = self::metadataArray($event);
        $existingMeta['occurrences'] = $instances;

        return new EventDto(
            sourceEventId: $event->source_event_id,
            sourceSystemId: (string) $event->software_system_id,
            title: $event->title,
            severity: self::resolveSeverity($event->severity),
            state: self::resolveState($event->state, EventState::Open),
            type: self::resolveType($event->type),
            metadata: $existingMeta,
        );
    }

    private function buildEventDto(
        AzDoAlert $alert,
        string $projectId,
        string $repoId,
        ?AzDoProject $project = null,
        ?AzDoRepository $repo = null,
    ): EventDto {
        $dto = AzDoNormalizer::toEvent($alert, $repoId, $project, $repo);

        $meta = $dto->metadata ?? [];
        $meta['sourceProjectId'] = $projectId;
        $meta['sourceRepoId'] = $repoId;

        return new EventDto(
            sourceEventId: $dto->sourceEventId,
            sourceSystemId: $projectId,
            title: $dto->title,
            severity: $dto->severity,
            state: $dto->state,
            type: $dto->type,
            sourceContainerId: $repoId,
            description: $dto->description,
            ruleId: $dto->ruleId,
            fingerprint: $dto->fingerprint,
            url: $dto->url,
            remediation: $dto->remediation,
            filePath: $dto->filePath,
            startLine: $dto->startLine,
            endLine: $dto->endLine,
            snippet: $dto->snippet,
            commitSha: $dto->commitSha,
            branch: $dto->branch,
            versionControlUrl: $dto->versionControlUrl,
            metadata: $meta,
            firstSeenAt: $dto->firstSeenAt,
            lastSeenAt: $dto->lastSeenAt,
        );
    }

    public function enrichmentJobFor(string $sourceId, SecurityEvent $event): ?ShouldQueue
    {
        if ($event->type !== EventType::Secret) {
            return null;
        }

        $metadata = self::metadataArray($event);
        $projectId = $metadata['sourceProjectId'] ?? null;
        $repoId    = $metadata['sourceRepoId'] ?? null;
        $alertId   = (int) $event->source_event_id;

        if (! is_string($projectId) || ! is_string($repoId) || $alertId === 0) {
            return null;
        }

        return new EnrichAzDoSecretJob($sourceId, $event->id, $projectId, $repoId, $alertId);
    }

    private function getClient(): AzDoClient
    {
        if ($this->client instanceof AzDoClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $pat = $this->vault->get('azdo.pat', null, true) ?? throw new \RuntimeException('AzDO PAT not configured');
        $organization = $this->vault->get('azdo.organization', null, true) ?? throw new \RuntimeException('AzDO organization not configured');
        $baseUrl = $this->vault->get('azdo.baseUrl', null, true) ?? 'https://dev.azure.com';
        $fingerprint = hash('sha256', implode('|', [$organization, $pat, $baseUrl]));

        if ($this->client === null || $this->clientFingerprint !== $fingerprint) {
            $this->client = new AzDoClient($organization, $pat, $baseUrl);
            $this->clientFingerprint = $fingerprint;
        }

        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadataArray(SecurityEvent $event): array
    {
        $raw = $event->getAttribute('metadata');

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }

    private static function resolveState(mixed $candidate, mixed $fallback): EventState
    {
        if ($candidate instanceof EventState) {
            return $candidate;
        }

        if (is_string($candidate)) {
            try {
                return EventState::from($candidate);
            } catch (\ValueError) {
                // Fall through to fallback handling.
            }
        }

        if ($fallback instanceof EventState) {
            return $fallback;
        }

        if (is_string($fallback)) {
            try {
                return EventState::from($fallback);
            } catch (\ValueError) {
                return EventState::Open;
            }
        }

        return EventState::Open;
    }

    private static function resolveSeverity(mixed $candidate): EventSeverity
    {
        if ($candidate instanceof EventSeverity) {
            return $candidate;
        }

        if (is_string($candidate)) {
            try {
                return EventSeverity::from($candidate);
            } catch (\ValueError) {
                return EventSeverity::Medium;
            }
        }

        return EventSeverity::Medium;
    }

    private static function resolveType(mixed $candidate): EventType
    {
        if ($candidate instanceof EventType) {
            return $candidate;
        }

        if (is_string($candidate)) {
            try {
                return EventType::from($candidate);
            } catch (\ValueError) {
                return EventType::Vulnerability;
            }
        }

        return EventType::Vulnerability;
    }
}
