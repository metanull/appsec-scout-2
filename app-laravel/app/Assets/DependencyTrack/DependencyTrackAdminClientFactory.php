<?php

declare(strict_types=1);

namespace App\Assets\DependencyTrack;

class DependencyTrackAdminClientFactory
{
    public function make(string $baseUrl): DependencyTrackAdminClient
    {
        return new DependencyTrackAdminClient($baseUrl);
    }
}
