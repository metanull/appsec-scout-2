<?php

namespace App\Trackers\Reconciliation;

use Illuminate\Support\Str;

/**
 * Outcome of a full reconciliation sweep: the links it produced, how many trackers were
 * searched, and every tracker whose search failed without stopping the sweep.
 */
final class ReconciliationSweep
{
    /**
     * @param  list<ReconciliationResult>  $results
     * @param  list<ReconciliationFailure>  $failures
     */
    public function __construct(
        public readonly array $results,
        public readonly int $trackersReconciled,
        public readonly array $failures,
    ) {}

    public function linksCreated(): int
    {
        return count(array_filter($this->results, fn (ReconciliationResult $result): bool => $result->alreadyLinked === false));
    }

    public function linksExisting(): int
    {
        return count($this->results) - $this->linksCreated();
    }

    public function trackersFailed(): int
    {
        return count($this->failures);
    }

    public function failureSummary(): ?string
    {
        if ($this->trackersFailed() === 0) {
            return null;
        }

        $details = implode('; ', array_map(fn (ReconciliationFailure $failure): string => $failure->describe(), $this->failures));

        return Str::limit(sprintf('%d tracker(s) failed, %d reconciled. %s', $this->trackersFailed(), $this->trackersReconciled, $details), 1000);
    }
}
