<?php

namespace App\Trackers\Reconciliation;

final class ReconciliationResult
{
    /** @param list<int> $linkedEventIds */
    public function __construct(
        public readonly string $trackerId,
        public readonly string $workItemId,
        public readonly array $linkedEventIds,
        public readonly bool $alreadyLinked,
    ) {}

    public static function alreadyLinked(string $trackerId, string $workItemId, int $eventId): self
    {
        return new self(
            trackerId: $trackerId,
            workItemId: $workItemId,
            linkedEventIds: [$eventId],
            alreadyLinked: true,
        );
    }

    /** @param list<int> $linkedEventIds */
    public static function linked(string $trackerId, string $workItemId, array $linkedEventIds): self
    {
        return new self(
            trackerId: $trackerId,
            workItemId: $workItemId,
            linkedEventIds: $linkedEventIds,
            alreadyLinked: false,
        );
    }
}
