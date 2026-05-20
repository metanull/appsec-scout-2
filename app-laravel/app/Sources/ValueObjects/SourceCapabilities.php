<?php

namespace App\Sources\ValueObjects;

use App\Models\Enums\EventType;

final class SourceCapabilities
{
    /**
     * @param  list<EventType>  $supportedEventTypes
     */
    public function __construct(
        public readonly bool $hasContainers = false,
        public readonly bool $canUpdateState = false,
        public readonly bool $canUpdateSeverity = false,
        public readonly bool $canAddComments = false,
        public readonly array $supportedEventTypes = [],
    ) {}
}
