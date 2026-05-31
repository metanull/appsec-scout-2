<?php

namespace Tests\Fakes;

use App\Credentials\CredentialField;
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

final class FakeMultiFieldSource implements Source
{
    public function id(): string
    {
        return 'fake-multi';
    }

    public function displayName(): string
    {
        return 'Fake Multi Source';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            hasContainers: false,
            canUpdateState: false,
            canUpdateSeverity: false,
            canAddComments: false,
            supportedEventTypes: [EventType::Vulnerability],
        );
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'fake-multi.username', label: 'Username', isSecret: false, required: true),
            new CredentialField(key: 'fake-multi.token', label: 'Token', isSecret: true, required: true),
        ];
    }

    public function testConnection(): TestResult
    {
        return TestResult::success();
    }

    /** @return iterable<SystemDto> */
    public function fetchSystems(): iterable
    {
        return [];
    }

    /** @return iterable<ContainerDto> */
    public function fetchContainers(SystemDto $system): iterable
    {
        return [];
    }

    /** @return iterable<EventDto> */
    public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable
    {
        return [];
    }

    public function pushEventState(SecurityEvent $event): PushResult
    {
        return PushResult::success();
    }

    public function fetchRawEvent(SecurityEvent $event): EventDto
    {
        return new EventDto(
            sourceEventId: $event->source_event_id,
            sourceSystemId: (string) $event->software_system_id,
            title: $event->title,
            severity: $event->severity,
            state: $event->state,
            type: $event->type,
        );
    }

    public function enrichEvent(SecurityEvent $event): ?EventDto
    {
        return null;
    }
}
