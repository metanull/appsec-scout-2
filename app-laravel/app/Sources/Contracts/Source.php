<?php

namespace App\Sources\Contracts;

use App\Models\SecurityEvent;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sources\ValueObjects\PushResult;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use Carbon\Carbon;

interface Source
{
    public function id(): string;

    public function displayName(): string;

    public function capabilities(): SourceCapabilities;

    /** @return list<string> */
    public function requiredCredentialKeys(): array;

    public function testConnection(): TestResult;

    /** @return iterable<SystemDto> */
    public function fetchSystems(): iterable;

    /** @return iterable<ContainerDto> */
    public function fetchContainers(SystemDto $system): iterable;

    /**
     * @return iterable<EventDto>
     */
    public function fetchEvents(?Carbon $since = null, ?SystemDto $system = null): iterable;

    public function pushEventState(SecurityEvent $event): PushResult;

    public function fetchRawEvent(SecurityEvent $event): EventDto;

    public function enrichEvent(SecurityEvent $event): ?EventDto;
}
