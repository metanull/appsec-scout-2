<?php

declare(strict_types=1);

namespace App\Assets\DependencyTrack;

class DependencyTrackClientFactory
{
    public function make(string $apiKey, string $baseUrl): DependencyTrackClient
    {
        return new DependencyTrackClient($apiKey, $baseUrl);
    }
}
