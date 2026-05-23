<?php

namespace App\Sources\Asoc;

use App\Credentials\Vault;
use App\Models\Article;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Sources\Contracts\EnrichesFetchedEvents;
use App\Sources\Contracts\Source;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use Carbon\Carbon;

final class AsocSource implements EnrichesFetchedEvents, Source
{
    private ?AsocClient $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return AsocNormalizer::SOURCE_ID;
    }

    public function displayName(): string
    {
        return 'HCL AppScan on Cloud';
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
                EventType::Dependency,
                EventType::Secret,
                EventType::Misconfiguration,
            ],
        );
    }

    /** @return list<string> */
    public function requiredCredentialKeys(): array
    {
        return ['asoc.baseUrl', 'asoc.keyId', 'asoc.keySecret'];
    }

    public function testConnection(): TestResult
    {
        try {
            return $this->getClient()->testConnection() ? TestResult::success() : TestResult::failure('Connection refused');
        } catch (\Throwable $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    /** @return iterable<SystemDto> */
    public function fetchSystems(): iterable
    {
        foreach ($this->getClient()->listApplications() as $app) {
            yield AsocNormalizer::toSystem($app);
        }
    }

    /** @return iterable<ContainerDto> */
    public function fetchContainers(SystemDto $system): iterable
    {
        foreach ($this->getClient()->listScans($system->sourceSystemId) as $scan) {
            yield AsocNormalizer::toContainer($scan, $system->sourceSystemId);
        }
    }

    /** @return iterable<EventDto> */
    public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable
    {
        $client = $this->getClient();
        $sinceDate = $since?->toDateTime();

        if ($system !== null) {
            foreach ($client->listIssues($system->sourceSystemId, $sinceDate) as $issue) {
                yield AsocNormalizer::toEvent($issue, $system->sourceSystemId);
            }

            return;
        }

        foreach ($this->fetchSystems() as $app) {
            foreach ($client->listIssues($app->sourceSystemId, $sinceDate) as $issue) {
                yield AsocNormalizer::toEvent($issue, $app->sourceSystemId);
            }
        }
    }

    public function pushEventState(SecurityEvent $event): PushResult
    {
        try {
            $appId = $event->softwareSystem?->source_system_id;
            if (! is_string($appId) || $appId === '') {
                return PushResult::failure('Missing ASoC application mapping');
            }

            $state = self::resolveState($event->pending_state, self::resolveState($event->state, EventState::Open));
            $comment = $event->pending_comment;
            $status = self::mapStateToSource($state);

            if ($state === EventState::Dismissed) {
                $reason = self::metadataValue($event, 'dismissalReason');
                if (is_string($reason) && $reason !== '') {
                    $comment = trim(($comment ?? '') . "\nDismissal reason: {$reason}");
                }
            }

            $this->getClient()->updateIssue($appId, $event->source_event_id, $status, $comment);

            return PushResult::success();
        } catch (\Throwable $e) {
            return PushResult::failure($e->getMessage());
        }
    }

    public function fetchRawEvent(SecurityEvent $event): EventDto
    {
        $issue = $this->getClient()->getIssue($event->source_event_id);
        $sourceSystem = $event->softwareSystem()->first();
        if ($sourceSystem === null) {
            throw new \RuntimeException('Missing ASoC source system mapping');
        }

        $sourceSystemId = (string) $sourceSystem->source_system_id;

        return AsocNormalizer::toEvent($issue, (string) $sourceSystemId);
    }

    public function enrichEvent(SecurityEvent $event): ?EventDto
    {
        $issueTypeId = self::metadataValue($event, 'issueTypeId');

        if (! is_string($issueTypeId) || $issueTypeId === '') {
            return null;
        }

        $language = self::metadataValue($event, 'language');
        $cveId = self::metadataValue($event, 'cve');
        $apiVulnName = self::metadataValue($event, 'apiVulnName');

        $markdown = $this->issueArticleMarkdown(
            $issueTypeId,
            is_string($language) ? $language : null,
            is_string($cveId) ? $cveId : null,
            is_string($apiVulnName) ? $apiVulnName : null,
        );

        if ($markdown === null) {
            return null;
        }

        $metadata = self::metadataArray($event);

        return new EventDto(
            sourceEventId: $event->source_event_id,
            sourceSystemId: (string) $event->software_system_id,
            title: $event->title,
            severity: self::resolveSeverity($event->severity),
            state: self::resolveState($event->state, EventState::Open),
            type: self::resolveType($event->type),
            sourceContainerId: $event->container?->source_container_id,
            description: $event->description,
            ruleId: $event->rule_id,
            fingerprint: $event->fingerprint,
            url: $event->url,
            remediation: $markdown,
            filePath: $event->file_path,
            startLine: $event->start_line,
            endLine: $event->end_line,
            snippet: $event->snippet,
            commitSha: $event->commit_sha,
            branch: $event->branch,
            versionControlUrl: $event->version_control_url,
            sourceData: $event->source_data,
            metadata: $metadata,
            firstSeenAt: self::toDateTime($event->first_seen_at),
            lastSeenAt: self::toDateTime($event->last_seen_at),
        );
    }

    public function enrichFetchedEvent(EventDto $event): EventDto
    {
        $metadata = $event->metadata ?? [];
        $issueTypeId = $metadata['issueTypeId'] ?? null;

        if (! is_string($issueTypeId) || $issueTypeId === '') {
            return $event;
        }

        $language = $metadata['language'] ?? null;
        $cveId = $metadata['cve'] ?? null;
        $apiVulnName = $metadata['apiVulnName'] ?? null;

        $markdown = $this->issueArticleMarkdown(
            $issueTypeId,
            is_string($language) ? $language : null,
            is_string($cveId) ? $cveId : null,
            is_string($apiVulnName) ? $apiVulnName : null,
        );

        if ($markdown === null) {
            return $event;
        }

        return new EventDto(
            sourceEventId: $event->sourceEventId,
            sourceSystemId: $event->sourceSystemId,
            title: $event->title,
            severity: $event->severity,
            state: $event->state,
            type: $event->type,
            sourceContainerId: $event->sourceContainerId,
            description: $event->description,
            ruleId: $event->ruleId,
            fingerprint: $event->fingerprint,
            url: $event->url,
            remediation: $markdown,
            filePath: $event->filePath,
            startLine: $event->startLine,
            endLine: $event->endLine,
            snippet: $event->snippet,
            commitSha: $event->commitSha,
            branch: $event->branch,
            versionControlUrl: $event->versionControlUrl,
            sourceData: $event->sourceData,
            metadata: $event->metadata,
            firstSeenAt: $event->firstSeenAt,
            lastSeenAt: $event->lastSeenAt,
        );
    }

    private function issueArticleMarkdown(string $issueTypeId, ?string $language, ?string $cveId, ?string $apiVulnName): ?string
    {
        $cacheLanguage = is_string($language) && $language !== '' ? $language : 'en';
        $cacheApiVuln = is_string($apiVulnName) && $apiVulnName !== '' ? $apiVulnName : null;

        $article = Article::query()->where([
            'issue_type_id' => $issueTypeId,
            'language' => $cacheLanguage,
            'api_vuln_name' => $cacheApiVuln,
        ])->first();

        $fetchedAt = self::toDateTime($article?->getAttribute('fetched_at'));
        $isExpired = $fetchedAt === null || $fetchedAt < now()->subDays(7);

        if ($article === null || $isExpired) {
            $markdown = $this->getClient()->getIssueArticleMarkdown(
                $issueTypeId,
                $cacheLanguage,
                $cveId,
                $cacheApiVuln,
            );

            if ($markdown !== null) {
                $article = Article::query()->updateOrCreate(
                    [
                        'issue_type_id' => $issueTypeId,
                        'language' => $cacheLanguage,
                        'api_vuln_name' => $cacheApiVuln,
                    ],
                    [
                        'fetched_at' => now(),
                        'markdown' => $markdown,
                    ],
                );
            }
        }

        if ($article === null || trim($article->markdown) === '') {
            return null;
        }

        return $article->markdown;
    }

    private function getClient(): AsocClient
    {
        if ($this->client instanceof AsocClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $keyId = $this->vault->get('asoc.keyId', null, true) ?? throw new \RuntimeException('ASoC keyId is not configured');
        $keySecret = $this->vault->get('asoc.keySecret', null, true) ?? throw new \RuntimeException('ASoC keySecret is not configured');
        $baseUrl = $this->vault->get('asoc.baseUrl', null, true) ?? 'https://cloud.appscan.com';
        $fingerprint = hash('sha256', implode('|', [$keyId, $keySecret, $baseUrl]));

        if ($this->client === null || $this->clientFingerprint !== $fingerprint) {
            $this->client = new AsocClient($keyId, $keySecret, $baseUrl);
            $this->clientFingerprint = $fingerprint;
        }

        return $this->client;
    }

    private static function mapStateToSource(EventState $state): string
    {
        return match ($state) {
            EventState::Open => 'New',
            EventState::InProgress => 'InProgress',
            EventState::Resolved => 'Fixed',
            EventState::Dismissed => 'Noise',
            default => 'Open',
        };
    }

    private static function resolveSeverity(mixed $value): EventSeverity
    {
        if ($value instanceof EventSeverity) {
            return $value;
        }

        if (is_string($value)) {
            $enum = EventSeverity::tryFrom($value);

            if ($enum instanceof EventSeverity) {
                return $enum;
            }
        }

        return EventSeverity::Medium;
    }

    private static function resolveState(mixed $value, EventState $default): EventState
    {
        if ($value instanceof EventState) {
            return $value;
        }

        if (is_string($value)) {
            $enum = EventState::tryFrom($value);

            if ($enum instanceof EventState) {
                return $enum;
            }
        }

        return $default;
    }

    private static function resolveType(mixed $value): EventType
    {
        if ($value instanceof EventType) {
            return $value;
        }

        if (is_string($value)) {
            $enum = EventType::tryFrom($value);

            if ($enum instanceof EventType) {
                return $enum;
            }
        }

        return EventType::Vulnerability;
    }

    private static function metadataValue(SecurityEvent $event, string $key): mixed
    {
        $metadata = self::metadataArray($event);

        return $metadata[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private static function metadataArray(SecurityEvent $event): array
    {
        $metadata = $event->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    private static function toDateTime(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
