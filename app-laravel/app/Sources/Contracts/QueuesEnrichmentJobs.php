<?php

namespace App\Sources\Contracts;

use App\Models\SecurityEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

interface QueuesEnrichmentJobs
{
    public function enrichmentJobFor(string $sourceId, SecurityEvent $event): ?ShouldQueue;
}
