<?php

namespace App\Trackers\Reconciliation;

use Illuminate\Support\Str;
use Throwable;

/**
 * One tracker whose tracker-wide reconciliation search failed during a full sweep.
 */
final class ReconciliationFailure
{
    public function __construct(
        public readonly string $trackerId,
        public readonly Throwable $exception,
    ) {}

    public function describe(): string
    {
        $message = trim($this->exception->getMessage());
        $message = Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 500);

        return "Reconciliation of tracker {$this->trackerId} failed: {$message}";
    }
}
