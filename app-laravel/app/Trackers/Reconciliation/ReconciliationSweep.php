<?php

namespace App\Trackers\Reconciliation;

use Illuminate\Support\Str;

/**
 * Outcome of a full reconciliation sweep: the links it produced, how many tracker
 * projects were searched, and every tracker call that failed without stopping the sweep.
 */
final class ReconciliationSweep
{
    /**
     * @param  list<ReconciliationResult>  $results
     * @param  list<ReconciliationFailure>  $failures
     * @param  int  $projectsSkipped  projects never searched because their tracker ran out of rate limit
     */
    public function __construct(
        public readonly array $results,
        public readonly int $projectsReconciled,
        public readonly array $failures,
        public readonly int $projectsSkipped = 0,
    ) {}

    public function linksCreated(): int
    {
        return count(array_filter($this->results, fn (ReconciliationResult $result): bool => $result->alreadyLinked === false));
    }

    public function linksExisting(): int
    {
        return count($this->results) - $this->linksCreated();
    }

    /** Projects that were not reconciled: the failed ones plus those skipped after a rate limit. */
    public function projectsFailed(): int
    {
        return count($this->failures) + $this->projectsSkipped;
    }

    public function failureSummary(): ?string
    {
        if ($this->projectsFailed() === 0) {
            return null;
        }

        $details = implode('; ', array_map(fn (ReconciliationFailure $failure): string => $failure->describe(), $this->failures));
        $skipped = $this->projectsSkipped > 0 ? sprintf(' (%d skipped after a rate limit)', $this->projectsSkipped) : '';

        return Str::limit(sprintf('%d tracker project(s) failed%s, %d reconciled. %s', $this->projectsFailed(), $skipped, $this->projectsReconciled, $details), 1000);
    }
}
