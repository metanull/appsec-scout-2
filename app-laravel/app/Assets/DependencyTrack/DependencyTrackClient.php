<?php

declare(strict_types=1);

namespace App\Assets\DependencyTrack;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

final class DependencyTrackClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? OutboundHttpFactory::create([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'headers' => [
                'X-Api-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function ping(): bool
    {
        try {
            $this->http->request('GET', 'api/v1/project', [
                'headers' => [
                    'X-Api-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => ['pageSize' => 1],
            ]);

            return true;
        } catch (ClientException) {
            return false;
        }
    }

    public function uploadBom(string $projectName, string $projectVersion, string $bomPayload): void
    {
        $this->http->request('PUT', 'api/v1/bom', [
            'headers' => [
                'X-Api-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'projectName' => $projectName,
                'projectVersion' => $projectVersion,
                'autoCreate' => true,
                'bom' => base64_encode($bomPayload),
            ],
        ]);
    }
}
