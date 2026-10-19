<?php

namespace App\Trackers;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * A tracker refused a request for rate-limit reasons and the client gave up waiting. Every
 * further call to that tracker would be refused too until the limit resets.
 */
final class TrackerRateLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly CarbonImmutable $resetsAt,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
