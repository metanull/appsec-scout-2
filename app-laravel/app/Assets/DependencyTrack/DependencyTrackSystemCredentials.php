<?php

declare(strict_types=1);

namespace App\Assets\DependencyTrack;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Http\OutboundHttpFactory;
use App\Sources\ValueObjects\TestResult;
use GuzzleHttp\Client;
use RuntimeException;

/**
 * Credential descriptor + connection probe for the Dependency-Track entry on the
 * System Credentials page. These are the two vault keys the app itself consumes
 * (PushSbomAttachmentToDependencyTrack); dependencytrack.adminPassword is
 * deliberately not exposed — it is Dependency-Track's own UI login, reachable via
 * the vault:get CLI only.
 */
final class DependencyTrackSystemCredentials
{
    public function __construct(
        private readonly Vault $vault,
        private readonly ?Client $httpClient = null,
    ) {}

    public function id(): string
    {
        return 'dependencytrack';
    }

    public function displayName(): string
    {
        return 'Dependency-Track';
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(
                key: 'dependencytrack.baseUrl',
                label: 'Base URL',
                isSecret: false,
                required: true,
                description: 'Dependency-Track API server base URL. In-cluster default: http://dependencytrack-apiserver:8080.',
            ),
            new CredentialField(
                key: 'dependencytrack.apiKey',
                label: 'API key',
                isSecret: true,
                required: true,
                description: 'Automation team API key, auto-provisioned by the dependencytrack-bootstrap container.',
            ),
        ];
    }

    public function testConnection(): TestResult
    {
        try {
            $baseUrl = $this->vault->get('dependencytrack.baseUrl', null) ?? 'http://dependencytrack-apiserver:8080';
            $apiKey = $this->vault->get('dependencytrack.apiKey', null)
                ?? throw new RuntimeException('Dependency-Track API key is not configured');

            $client = $this->httpClient ?? OutboundHttpFactory::create([
                'base_uri' => rtrim($baseUrl, '/') . '/',
            ]);

            $response = $client->request('GET', 'api/v1/team/self', [
                'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300
                ? TestResult::success()
                : TestResult::failure("Dependency-Track responded with HTTP {$status}");
        } catch (\Throwable $e) {
            return TestResult::failure($e->getMessage());
        }
    }
}
