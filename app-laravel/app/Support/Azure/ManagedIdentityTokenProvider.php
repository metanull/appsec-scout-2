<?php

namespace App\Support\Azure;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Acquires short-lived OAuth2 access tokens from the Azure Instance Metadata
 * Service (IMDS), for use as the PDO password when authenticating to Azure
 * Database for PostgreSQL with a Managed Identity instead of a stored secret.
 *
 * IMDS is a local link-local endpoint (169.254.169.254), so a slow or missing
 * response should fail fast rather than hang the request.
 */
class ManagedIdentityTokenProvider
{
    private const IMDS_TOKEN_ENDPOINT = 'http://169.254.169.254/metadata/identity/oauth2/token';

    /**
     * PostgreSQL Flexible Server's fixed AAD resource ID. This is not
     * configurable — it is the same for every Azure Database for PostgreSQL
     * instance.
     */
    private const POSTGRES_RESOURCE = 'https://ossrdbms-aad.database.windows.net';

    private const IMDS_API_VERSION = '2019-08-01';

    private const TIMEOUT_SECONDS = 5;

    /**
     * Fetch a fresh access token for Azure Database for PostgreSQL from IMDS.
     *
     * @param  string|null  $clientId  The client ID of a user-assigned managed
     *                                 identity. Omit to use the system-assigned identity.
     *
     * @throws RuntimeException When IMDS cannot be reached, responds with a
     *                          non-2xx status, or returns a body without an access token.
     */
    public function getPostgresAccessToken(?string $clientId = null): string
    {
        $query = array_filter([
            'api-version' => self::IMDS_API_VERSION,
            'resource' => self::POSTGRES_RESOURCE,
            'client_id' => $clientId,
        ], fn (?string $value): bool => $value !== null);

        $response = Http::withHeaders(['Metadata' => 'true'])
            ->timeout(self::TIMEOUT_SECONDS)
            ->get(self::IMDS_TOKEN_ENDPOINT, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                'Azure Instance Metadata Service returned an unsuccessful response '
                . "(HTTP {$response->status()}) while fetching a managed identity access token."
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(
                'Azure Instance Metadata Service response did not include an access_token.'
            );
        }

        return $token;
    }
}
