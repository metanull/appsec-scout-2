<?php

namespace App\Trackers\Contracts;

interface RateLimitedTracker
{
    public function rateLimitDelay(): int;
}
