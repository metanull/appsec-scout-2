<?php

namespace App\Database;

use App\Support\Azure\ManagedIdentityTokenProvider;
use Illuminate\Database\Connectors\PostgresConnector;

/**
 * Drop-in replacement for Laravel's pgsql connector that transparently swaps
 * the configured password for a freshly-fetched Azure Managed Identity access
 * token when a connection opts in via `azure_managed_identity`.
 *
 * Registered as the `db.connector.pgsql` binding for every pgsql connection
 * (see AppServiceProvider), so this class must remain a safe passthrough for
 * connections that do not opt in.
 */
class AzureManagedIdentityPostgresConnector extends PostgresConnector
{
    public function __construct(private readonly ManagedIdentityTokenProvider $tokenProvider) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config)
    {
        if ($config['azure_managed_identity'] ?? false) {
            $config['password'] = $this->tokenProvider->getPostgresAccessToken(
                $config['azure_managed_identity_client_id'] ?? null
            );
        }

        return parent::connect($config);
    }
}
