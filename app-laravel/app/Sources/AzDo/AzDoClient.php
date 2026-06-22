<?php

namespace App\Sources\AzDo;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

final class AzDoClient
{
    private const API_VERSION = '7.2-preview.1';

    private readonly Client $http;

    private readonly Client $advSecHttp;

    public function __construct(
        private readonly string $organization,
        string $pat,
        string $baseUrl = 'https://dev.azure.com',
        ?Client $httpClient = null,
        ?Client $advSecHttpClient = null,
    ) {
        if ($httpClient !== null && $advSecHttpClient !== null) {
            $this->http = $httpClient;
            $this->advSecHttp = $advSecHttpClient;

            return;
        }

        $encoded = base64_encode(':' . $pat);
        $authHeader = ['Authorization' => 'Basic ' . $encoded];

        $orgUrl = rtrim($baseUrl, '/') . '/' . $this->organization . '/';

        $this->http = OutboundHttpFactory::create([
            'base_uri' => $orgUrl,
            'headers' => $authHeader,
        ]);

        $advSecBaseUrl = str_ireplace('://dev.azure.com', '://advsec.dev.azure.com', $baseUrl);
        if ($advSecBaseUrl === $baseUrl) {
            $advSecBaseUrl = 'https://advsec.dev.azure.com';
        }

        $advSecOrgUrl = rtrim($advSecBaseUrl, '/') . '/' . $this->organization . '/';

        $this->advSecHttp = OutboundHttpFactory::create([
            'base_uri' => $advSecOrgUrl,
            'headers' => $authHeader,
        ]);
    }

    public function testConnection(): bool
    {
        $response = $this->http->get('_apis/projects', ['query' => ['$top' => 1, 'api-version' => '7.0']]);

        return $response->getStatusCode() === 200;
    }

    /**
     * @return list<AzDoProject>
     */
    public function listProjects(): array
    {
        $all = [];
        $continuationToken = null;

        do {
            $query = ['$top' => 100, 'stateFilter' => 'wellFormed', 'api-version' => '7.0'];

            if ($continuationToken !== null) {
                $query['continuationToken'] = $continuationToken;
            }

            $response = $this->http->get('_apis/projects', ['query' => $query]);
            /** @var array{value: list<array<string,mixed>>, count: int} $data */
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($data['value'] as $item) {
                $all[] = AzDoProject::fromArray($item);
            }

            $continuationToken = $response->getHeaderLine('x-ms-continuationtoken') ?: null;
        } while ($continuationToken !== null);

        return $all;
    }

    /**
     * @return list<AzDoRepository>
     */
    public function listRepositories(string $projectId): array
    {
        $all = [];
        $continuationToken = null;

        do {
            $query = ['api-version' => '7.0'];

            if ($continuationToken !== null) {
                $query['continuationToken'] = $continuationToken;
            }

            $response = $this->http->get("{$projectId}/_apis/git/repositories", ['query' => $query]);

            /** @var array{value: list<array<string,mixed>>} $data */
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($data['value'] as $item) {
                $all[] = AzDoRepository::fromArray($item);
            }

            $continuationToken = $response->getHeaderLine('x-ms-continuationtoken') ?: null;
        } while ($continuationToken !== null);

        return $all;
    }

    /**
     * @return list<AzDoAlert>
     */
    public function listAlerts(string $projectName, string $repoId, string $alertType, ?\DateTimeInterface $since = null): array
    {
        $all = [];
        $continuationToken = null;

        try {
            do {
                // The AzDO Advanced Security API ignores the top-level `alertType` parameter
                // whenever any `criteria.*` parameter is also present. Use `criteria.alertType`
                // so the filter is honoured in both full and incremental (modifiedSince) fetches.
                //
                // Do NOT pass expand=1 on the list request. The API silently truncates the result
                // set when per-alert payload size causes the response to exceed an undocumented
                // limit, and it does not emit a continuation token for the dropped records.
                // Individual alert details are fetched separately via getAlert() as needed.
                $query = [
                    'criteria.alertType' => $alertType,
                    'top' => 500,
                    'api-version' => self::API_VERSION,
                ];

                if ($since !== null) {
                    $query['criteria.modifiedSince'] = $since->format(\DateTimeInterface::ATOM);
                }

                if ($continuationToken !== null) {
                    $query['continuationToken'] = $continuationToken;
                }

                $response = $this->advSecHttp->get(
                    "{$projectName}/_apis/alert/repositories/{$repoId}/alerts",
                    ['query' => $query],
                );

                /** @var array{value: list<array<string,mixed>>} $data */
                $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

                foreach ($data['value'] as $item) {
                    $all[] = AzDoAlert::fromArray($item);
                }

                $continuationToken = $response->getHeaderLine('x-ms-continuationtoken') ?: null;
            } while ($continuationToken !== null);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 400 || $status === 404) {
                return [];
            }

            throw $e;
        }

        return $all;
    }

    public function getAlert(string $projectName, string $repoId, int $alertId): AzDoAlert
    {
        $response = $this->advSecHttp->get(
            "{$projectName}/_apis/alert/repositories/{$repoId}/alerts/{$alertId}",
            ['query' => ['expand' => '1', 'api-version' => self::API_VERSION]],
        );

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return AzDoAlert::fromArray($data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAlertInstances(string $projectName, string $repoId, int $alertId): array
    {
        $response = $this->advSecHttp->get(
            "{$projectName}/_apis/alert/repositories/{$repoId}/alerts/{$alertId}/instances",
            ['query' => ['api-version' => self::API_VERSION]],
        );

        /** @var array{value: list<array<string,mixed>>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data['value'];
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function updateAlert(string $projectName, string $repoId, int $alertId, array $update): void
    {
        $this->advSecHttp->patch(
            "{$projectName}/_apis/alert/repositories/{$repoId}/alerts/{$alertId}",
            [
                'query' => ['api-version' => self::API_VERSION],
                'json' => $update,
            ],
        );
    }
}
