<?php

namespace App\Sources\Detectify;

use App\Credentials\Vault;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Sources\Contracts\Source;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use Carbon\Carbon;

final class DetectifySource implements Source
{
    private ?DetectifyClient $client = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return DetectifyNormalizer::SOURCE_ID;
    }

    public function displayName(): string
    {
        return 'Detectify';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            hasContainers: false,
            canUpdateState: true,
            canUpdateSeverity: false,
            canAddComments: true,
            supportedEventTypes: [
                EventType::Vulnerability,
                EventType::Misconfiguration,
            ],
        );
    }

    /** @return list<string> */
    public function requiredCredentialKeys(): array
    {
        return ['detectify.apiKey'];
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
        foreach ($this->getClient()->listDomains() as $domain) {
            yield DetectifyNormalizer::toSystem($domain);
        }
    }

    /** @return iterable<ContainerDto> */
    public function fetchContainers(SystemDto $system): iterable
    {
        return [];
    }

    /** @return iterable<EventDto> */
    public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable
    {
        $client = $this->getClient();

        if ($system !== null) {
            foreach ($client->listFindings($system->sourceSystemId) as $finding) {
                $finding['asset_token'] = $system->sourceSystemId;
                yield DetectifyNormalizer::toEvent($finding);
            }

            return;
        }

        foreach ($client->listDomains() as $domain) {
            $token = (string) ($domain['token'] ?? $domain['asset_token'] ?? '');
            if ($token === '') {
                continue;
            }

            foreach ($client->listFindings($token) as $finding) {
                $finding['asset_token'] = $token;
                yield DetectifyNormalizer::toEvent($finding);
            }
        }
    }

    public function pushEventState(SecurityEvent $event): PushResult
    {
        try {
            $metadata = self::metadataArray($event);
            $domainToken = $metadata['domainToken'] ?? $event->softwareSystem?->source_system_id;

            if (! is_string($domainToken) || $domainToken === '') {
                return PushResult::failure('Missing domain token');
            }

            $targetState = self::resolveState($event->pending_state, self::resolveState($event->state, EventState::Open));

            $this->getClient()->updateFindingStatus(
                $domainToken,
                $event->source_event_id,
                DetectifyNormalizer::mapStateToSource($targetState),
                $event->pending_comment,
            );

            return PushResult::success();
        } catch (\Throwable $e) {
            return PushResult::failure($e->getMessage());
        }
    }

    public function fetchRawEvent(SecurityEvent $event): EventDto
    {
        $metadata = self::metadataArray($event);
        $domainToken = is_string($metadata['domainToken'] ?? null) ? $metadata['domainToken'] : '';

        $finding = $this->getClient()->getFinding($domainToken, $event->source_event_id);
        $finding['asset_token'] = $domainToken;

        return DetectifyNormalizer::toEvent($finding);
    }

    public function enrichEvent(SecurityEvent $event): ?EventDto
    {
        return null;
    }

    private function getClient(): DetectifyClient
    {
        if ($this->client === null) {
            $apiKey = $this->vault->get('detectify.apiKey', null) ?? throw new \RuntimeException('Detectify API key is not configured');
            $baseUrl = $this->vault->get('detectify.baseUrl', null) ?? 'https://api.detectify.com';

            $this->client = new DetectifyClient($apiKey, $baseUrl);
        }

        return $this->client;
    }

    private static function resolveState(mixed $value, EventState $fallback): EventState
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

        return $fallback;
    }

    /** @return array<string, mixed> */
    private static function metadataArray(SecurityEvent $event): array
    {
        $metadata = $event->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }
}
