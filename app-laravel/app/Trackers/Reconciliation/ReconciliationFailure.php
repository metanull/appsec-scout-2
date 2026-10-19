<?php

namespace App\Trackers\Reconciliation;

use Illuminate\Support\Str;
use Throwable;

/**
 * One tracker call that failed during a full sweep: either listing a tracker's projects
 * (projectKey null) or searching one project for reconciliation candidates.
 */
final class ReconciliationFailure
{
    public function __construct(
        public readonly string $trackerId,
        public readonly ?string $projectKey,
        public readonly Throwable $exception,
    ) {}

    public function describe(): string
    {
        $message = trim($this->exception->getMessage());
        $message = Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 500);

        return $this->projectKey === null
            ? "Project discovery for tracker {$this->trackerId} failed: {$message}"
            : "Reconciliation of {$this->trackerId} project {$this->projectKey} failed: {$message}";
    }
}
