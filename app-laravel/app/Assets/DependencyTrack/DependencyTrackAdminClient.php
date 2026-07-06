<?php

declare(strict_types=1);

namespace App\Assets\DependencyTrack;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;

/**
 * Talks to Dependency-Track as an authenticated administrator to provision the
 * automation team used by the SBOM export command. Distinct from
 * DependencyTrackClient, which only ever authenticates with a team API key.
 */
final class DependencyTrackAdminClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $baseUrl,
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? OutboundHttpFactory::create([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
        ]);
    }

    /**
     * Returns the JWT on success, or null when Dependency-Track requires a
     * password change before login is allowed.
     */
    public function login(string $username, string $password): ?string
    {
        try {
            $response = $this->http->request('POST', 'api/v1/user/login', [
                'headers' => ['Accept' => 'text/plain'],
                'form_params' => ['username' => $username, 'password' => $password],
            ]);

            return (string) $response->getBody();
        } catch (ClientException $e) {
            if ((string) $e->getResponse()->getBody() === 'FORCE_PASSWORD_CHANGE') {
                return null;
            }

            throw $e;
        }
    }

    public function forceChangePassword(string $username, string $currentPassword, string $newPassword): void
    {
        $this->http->request('POST', 'api/v1/user/forceChangePassword', [
            'form_params' => [
                'username' => $username,
                'password' => $currentPassword,
                'newPassword' => $newPassword,
                'confirmPassword' => $newPassword,
            ],
        ]);
    }

    /** @return array{uuid: string, permissions: list<string>, apiKeyPublicIds: list<string>} */
    public function findOrCreateTeam(string $token, string $name): array
    {
        foreach ($this->getJson('GET', 'api/v1/team', $token) as $team) {
            if (is_array($team) && ($team['name'] ?? null) === $name) {
                return self::normalizeTeam($team);
            }
        }

        $created = $this->request('PUT', 'api/v1/team', $token, ['json' => ['name' => $name]]);

        return self::normalizeTeam(self::decodeObject($created));
    }

    public function grantPermission(string $token, string $permission, string $teamUuid): void
    {
        $this->request('POST', "api/v1/permission/{$permission}/team/{$teamUuid}", $token);
    }

    public function deleteApiKey(string $token, string $publicId): void
    {
        $this->request('DELETE', "api/v1/team/key/{$publicId}", $token);
    }

    public function createApiKey(string $token, string $teamUuid): string
    {
        return self::extractKey($this->request('PUT', "api/v1/team/{$teamUuid}/key", $token));
    }

    public function regenerateApiKey(string $token, string $publicId): string
    {
        return self::extractKey($this->request('POST', "api/v1/team/key/{$publicId}", $token));
    }

    private static function extractKey(string $body): string
    {
        $payload = self::decodeObject($body);
        $key = $payload['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Dependency-Track did not return an API key.');
        }

        return $key;
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $path, string $token, array $options = []): string
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ]);

        return (string) $this->http->request($method, $path, $options)->getBody();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<mixed>
     */
    private function getJson(string $method, string $path, string $token, array $options = []): array
    {
        $body = $this->request($method, $path, $token, $options);

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $body): array
    {
        if ($body === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array{uuid: string, permissions: list<string>, apiKeyPublicIds: list<string>}
     */
    private static function normalizeTeam(array $team): array
    {
        $permissions = [];

        foreach ((array) ($team['permissions'] ?? []) as $permission) {
            if (is_array($permission) && is_string($permission['name'] ?? null)) {
                $permissions[] = $permission['name'];
            }
        }

        $apiKeyPublicIds = [];

        foreach ((array) ($team['apiKeys'] ?? []) as $apiKey) {
            if (is_array($apiKey) && is_string($apiKey['publicId'] ?? null)) {
                $apiKeyPublicIds[] = $apiKey['publicId'];
            }
        }

        return [
            'uuid' => (string) $team['uuid'],
            'permissions' => $permissions,
            'apiKeyPublicIds' => $apiKeyPublicIds,
        ];
    }
}
