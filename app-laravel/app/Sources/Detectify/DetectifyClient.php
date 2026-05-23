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
                'X-Detectify-Key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function testConnection(): bool
    {
        $this->listDomains(1);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function listDomains(int $pageSize = 100): array
    {
        return $this->fetchAllPages('rest/v2/assets/', 'assets', ['pageSize' => $pageSize]);
    }

    /** @return list<array<string, mixed>> */
    public function listFindings(string $domainToken): array
    {
        return $this->fetchAllPages('rest/v2/vulnerabilities/', 'vulnerabilities', [
            'pageSize' => 100,
            'asset_token[]' => $domainToken,
        ]);
    }

    /** @return array<string, mixed> */
    public function getFinding(string $domainToken, string $uuid): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->request('GET', 'rest/v2/vulnerabilities/uuid/' . rawurlencode($uuid) . '/');

        $finding = $payload['vulnerability'] ?? $payload;

        return is_array($finding) ? $finding : [];
    }

    public function updateFindingStatus(string $domainToken, string $uuid, string $status, ?string $note = null): void
    {
        $this->request('POST', 'rest/v2/vulnerabilities/uuid/' . rawurlencode($uuid) . '/' . self::statusAction($status) . '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(string $path, string $itemsKey, array $query): array
    {
        $items = [];
        $marker = null;

        do {
            $pageQuery = $query;

            if (is_string($marker) && $marker !== '') {
                $pageQuery['marker'] = $marker;
            }

            $payload = $this->request('GET', $path, ['query' => $pageQuery]);
            $pageItems = $payload[$itemsKey] ?? $payload['results'] ?? [];

            if (is_array($pageItems)) {
                foreach ($pageItems as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }

            $marker = ($payload['has_more'] ?? false) === true && is_string($payload['next_marker'] ?? null)
                ? $payload['next_marker']
                : null;
        } while ($marker !== null);

        return $items;
    }

    private static function statusAction(string $status): string
    {
        return match ($status) {
            'patched' => 'setfixedstatus',
            'accepted_risk' => 'setacceptedriskstatus',
            'false_positive' => 'setfalsepositivestatus',
            default => 'unsetfixedstatus',
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-Detectify-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ]);

        $response = $this->http->request($method, ltrim($path, '/'), $options);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
