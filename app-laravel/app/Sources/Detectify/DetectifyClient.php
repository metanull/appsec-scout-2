<?php

namespace App\Sources\Detectify;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;

final class DetectifyClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.detectify.com',
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? OutboundHttpFactory::create([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'headers' => [
                'Authorization' => $this->apiKey,
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function testConnection(): bool
    {
        $this->listDomains();

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function listDomains(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->request('GET', 'v2/domains/');

        /** @var list<array<string, mixed>> $items */
        $items = $payload['results'] ?? $payload['domains'] ?? [];

        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function listFindings(string $domainToken): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->request('GET', "v2/domains/{$domainToken}/findings/");

        /** @var list<array<string, mixed>> $items */
        $items = $payload['results'] ?? $payload['findings'] ?? [];

        return $items;
    }

    /** @return array<string, mixed> */
    public function getFinding(string $domainToken, string $uuid): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->request('GET', "v2/domains/{$domainToken}/findings/{$uuid}/");

        return $payload;
    }

    public function updateFindingStatus(string $domainToken, string $uuid, string $status, ?string $note = null): void
    {
        $this->request('PATCH', "v2/domains/{$domainToken}/findings/{$uuid}/", [
            'json' => [
                'status' => $status,
                'note' => $note,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $response = $this->http->request($method, ltrim($path, '/'), $options);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
