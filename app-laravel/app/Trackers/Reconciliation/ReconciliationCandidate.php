<?php

namespace App\Trackers\Reconciliation;

final class ReconciliationCandidate
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $trackerId,
        public readonly string $workItemId,
    ) {}
}
