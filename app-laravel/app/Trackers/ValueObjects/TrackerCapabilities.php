<?php

namespace App\Trackers\ValueObjects;

final class TrackerCapabilities
{
    /**
     * @param  list<string>  $supportedItemTypes
     */
    public function __construct(
        public readonly bool $supportsLabels = false,
        public readonly bool $supportsPriority = false,
        public readonly bool $supportsAssignee = false,
        public readonly bool $supportsParent = false,
        public readonly array $supportedItemTypes = [],
        public readonly int $maxDescriptionBytes = 16384,
    ) {}
}
