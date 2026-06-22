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

class FakeSource implements Source
{
    /** @var list<SystemDto> */
    private array $systems = [];

    /** @var array<string, list<ContainerDto>> */
    private array $containers = [];

    /** @var list<EventDto> */
    private array $events = [];

    private ?EventDto $rawEvent = null;

    private bool $connectionOk = true;

    private bool $pushOk = true;

    /** @var callable(SecurityEvent): PushResult|null */
    private $pushCallback = null;

    public string $lastPushedState = '';

    public int $pushCalls = 0;

    public function id(): string
    {
        return 'fake';
    }

    public function displayName(): string
    {
        return 'Fake Source';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            hasContainers: true,
            canUpdateState: true,
            canUpdateSeverity: false,
            canAddComments: true,
            supportedEventTypes: [EventType::Vulnerability, EventType::Secret],
        );
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'fake.apiKey', label: 'API Key', isSecret: true, required: true),
        ];
    }

    public function testConnection(): TestResult
    {
        return $this->connectionOk ? TestResult::success() : TestResult::failure('connection refused');
    }

    /** @return iterable<SystemDto> */
    public function fetchSystems(): iterable
    {
        return $this->systems;
    }

    /** @return iterable<ContainerDto> */
    public function fetchContainers(SystemDto $system): iterable
    {
        return $this->containers[$system->sourceSystemId] ?? [];
    }

    /** @return iterable<EventDto> */
    public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable
    {
        return $this->events;
    }

    public function pushEventState(SecurityEvent $event): PushResult
    {
        $this->pushCalls++;

        if (is_callable($this->pushCallback)) {
            return ($this->pushCallback)($event);
        }

        if ($this->pushOk) {
            $this->lastPushedState = $event->pending_state?->value ?? '';
        }

        return $this->pushOk ? PushResult::success() : PushResult::failure('upstream error');
    }

    public function fetchRawEvent(SecurityEvent $event): EventDto
    {
        if ($this->rawEvent instanceof EventDto) {
            return $this->rawEvent;
        }

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

    public function withSystems(SystemDto ...$systems): self
    {
        $this->systems = $systems;

        return $this;
    }

    public function withContainers(string $systemId, ContainerDto ...$containers): self
    {
        $this->containers[$systemId] = $containers;

        return $this;
    }

    public function withEvents(EventDto ...$events): self
    {
        $this->events = $events;

        return $this;
    }

    public function withRawEvent(EventDto $event): self
    {
        $this->rawEvent = $event;

        return $this;
    }

    public function withConnectionFailure(): self
    {
        $this->connectionOk = false;

        return $this;
    }

    public function withPushFailure(): self
    {
        $this->pushOk = false;

        return $this;
    }

    /** @param callable(SecurityEvent): PushResult $callback */
    public function withPushCallback(callable $callback): self
    {
        $this->pushCallback = $callback;

        return $this;
    }
}
