<?php

namespace App\Triage;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;

final class CodesearchClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $organization,
        string $pat,
        ?Client $http = null,
    ) {
        if ($http instanceof Client) {
            $this->http = $http;

            return;
        }

        $this->http = OutboundHttpFactory::create([
            'base_uri' => sprintf('https://almsearch.dev.azure.com/%s/', $this->organization),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(':' . $pat),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @param  array<string, list<string>>  $filters
     * @return array<string, mixed>
     */
    public function search(string $searchText, array $filters = []): array
    {
        $payload = [
            'searchText' => $searchText,
            '$top' => 100,
            '$skip' => 0,
        ];

        if ($filters !== []) {
            $payload['filters'] = $filters;
        }

        $response = $this->http->post('_apis/search/codeSearchResults', [
            'query' => ['api-version' => '7.1'],
            'json' => $payload,
        ]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
