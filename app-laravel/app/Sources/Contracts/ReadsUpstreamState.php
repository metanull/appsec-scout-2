<?php

namespace App\Sources\Contracts;

use App\Models\Enums\EventState;
use App\Models\SecurityEvent;

/**
 * A source that can read just an alert's current state, by a cheaper or more reliable path than
 * reading the whole alert (`Source::fetchRawEvent()`). Used to confirm a push the source refused.
 */
interface ReadsUpstreamState
{
    public function fetchUpstreamState(SecurityEvent $event): EventState;
}
