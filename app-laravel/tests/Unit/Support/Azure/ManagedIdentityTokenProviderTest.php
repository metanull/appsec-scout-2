<?php

use App\Support\Azure\ManagedIdentityTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('returns the access token from a successful IMDS response', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response([
            'access_token' => 'imds-issued-token',
            'expires_in' => '3599',
        ], 200),
    ]);

    $token = (new ManagedIdentityTokenProvider)->getPostgresAccessToken();

    expect($token)->toBe('imds-issued-token');
});

it('throws when IMDS responds with a non-2xx status', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response('unauthorized', 401),
    ]);

    (new ManagedIdentityTokenProvider)->getPostgresAccessToken();
})->throws(RuntimeException::class);

it('throws when the IMDS response is missing access_token', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response([
            'expires_in' => '3599',
        ], 200),
    ]);

    (new ManagedIdentityTokenProvider)->getPostgresAccessToken();
})->throws(RuntimeException::class);

it('throws when the IMDS response has an empty access_token', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response([
            'access_token' => '',
        ], 200),
    ]);

    (new ManagedIdentityTokenProvider)->getPostgresAccessToken();
})->throws(RuntimeException::class);

it('sends the Metadata header and the correct api-version and resource query params', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response([
            'access_token' => 'token',
        ], 200),
    ]);

    (new ManagedIdentityTokenProvider)->getPostgresAccessToken();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://169.254.169.254/metadata/identity/oauth2/token'
            . '?api-version=2019-08-01&resource=https%3A%2F%2Fossrdbms-aad.database.windows.net'
            && $request->hasHeader('Metadata', 'true');
    });
});

it('adds client_id to the query when a client ID is passed', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response([
            'access_token' => 'token',
        ], 200),
    ]);

    (new ManagedIdentityTokenProvider)->getPostgresAccessToken('11111111-2222-3333-4444-555555555555');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'client_id=11111111-2222-3333-4444-555555555555');
    });
});

it('omits client_id from the query when no client ID is passed', function () {
    Http::fake([
        'http://169.254.169.254/metadata/identity/oauth2/token*' => Http::response([
            'access_token' => 'token',
        ], 200),
    ]);

    (new ManagedIdentityTokenProvider)->getPostgresAccessToken();

    Http::assertSent(function (Request $request): bool {
        return ! str_contains($request->url(), 'client_id');
    });
});
