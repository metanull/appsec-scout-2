<?php

use App\Database\AzureManagedIdentityPostgresConnector;
use App\Support\Azure\ManagedIdentityTokenProvider;
use Illuminate\Support\Facades\Http;

it('does not call the token provider when azure_managed_identity is false', function () {
    Http::fake();

    $tokenProvider = Mockery::mock(ManagedIdentityTokenProvider::class);
    $tokenProvider->shouldNotReceive('getPostgresAccessToken');

    $connector = new AzureManagedIdentityPostgresConnector($tokenProvider);

    // We can't complete a real PDO connection in this test environment, so we
    // only assert on the guarded behaviour: no IMDS call is made and the
    // config is otherwise untouched before parent::connect() takes over.
    try {
        $connector->connect([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'appsec_scout',
            'password' => 'stored-password',
            'azure_managed_identity' => false,
        ]);
    } catch (PDOException) {
        // Expected: no live Postgres server is reachable in this test.
    }

    Http::assertNothingSent();
});

it('does not call the token provider when azure_managed_identity is absent', function () {
    Http::fake();

    $tokenProvider = Mockery::mock(ManagedIdentityTokenProvider::class);
    $tokenProvider->shouldNotReceive('getPostgresAccessToken');

    $connector = new AzureManagedIdentityPostgresConnector($tokenProvider);

    try {
        $connector->connect([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'appsec_scout',
            'password' => 'stored-password',
        ]);
    } catch (PDOException) {
        // Expected: no live Postgres server is reachable in this test.
    }

    Http::assertNothingSent();
});

it('replaces the password with a fetched token when azure_managed_identity is true', function () {
    $tokenProvider = Mockery::mock(ManagedIdentityTokenProvider::class);
    $tokenProvider->shouldReceive('getPostgresAccessToken')
        ->once()
        ->with(null)
        ->andReturn('freshly-fetched-token');

    $connector = new AzureManagedIdentityPostgresConnector($tokenProvider);

    try {
        $connector->connect([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'appsec_scout',
            'password' => 'stored-password',
            'azure_managed_identity' => true,
        ]);
    } catch (PDOException) {
        // Expected: no live Postgres server is reachable in this test. The
        // mock expectation above still verifies the token provider was
        // called exactly once before the (failing) PDO connection attempt.
    }
});

it('passes the configured client ID through to the token provider', function () {
    $tokenProvider = Mockery::mock(ManagedIdentityTokenProvider::class);
    $tokenProvider->shouldReceive('getPostgresAccessToken')
        ->once()
        ->with('11111111-2222-3333-4444-555555555555')
        ->andReturn('freshly-fetched-token');

    $connector = new AzureManagedIdentityPostgresConnector($tokenProvider);

    try {
        $connector->connect([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'appsec_scout',
            'password' => 'stored-password',
            'azure_managed_identity' => true,
            'azure_managed_identity_client_id' => '11111111-2222-3333-4444-555555555555',
        ]);
    } catch (PDOException) {
        // Expected: no live Postgres server is reachable in this test.
    }
});
