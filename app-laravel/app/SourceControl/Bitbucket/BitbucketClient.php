<?php

namespace App\SourceControl\Bitbucket;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;

/**
 * Bitbucket Cloud REST API v2 client, workspace-scoped, authenticated with an
 * Atlassian API token / access token via `Authorization: Bearer` (app passwords
 * are deliberately not supported — Bitbucket Cloud is sunsetting them). Mirrors
 * the thin-wrapper shape of AzDoClient/GitHubClient over Laravel's outbound HTTP
 * factory.
 */
final class BitbucketClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $workspace,
        string $token,
        string $baseUrl = 'https://api.bitbucket.org/2.0',
        ?Client $httpClient = null,
    ) {
        if ($httpClient !== null) {
            $this->http = $httpClient;

            return;
        }

        $this->http = OutboundHttpFactory::create([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function testConnection(): bool
    {
        $response = $this->http->get('repositories/' . rawurlencode($this->workspace), [
            'query' => ['pagelen' => 1],
        ]);

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * List every repository in the workspace, following the response `next` link
     * across pages.
     *
     * @return list<BitbucketRepository>
     */
    public function listRepositories(): array
    {
        $all = [];
        $url = 'repositories/' . rawurlencode($this->workspace);
        $options = ['query' => ['pagelen' => 100]];

        do {
            $response = $this->http->get($url, $options);

            /** @var array{values?: list<array<string, mixed>>, next?: string} $data */
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($data['values'] ?? [] as $item) {
                $all[] = BitbucketRepository::fromArray($item);
            }

            // The `next` link is an absolute URL that already carries pagelen/page,
            // so follow it verbatim (absolute URIs bypass the client base_uri).
            $next = isset($data['next']) && is_string($data['next']) && $data['next'] !== '' ? $data['next'] : null;
            $url = $next;
            $options = [];
        } while ($url !== null);

        return $all;
    }
}
