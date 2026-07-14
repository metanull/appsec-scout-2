<?php

use App\Credentials\Vault;
use App\SourceControl\AzDo\AzDoRepos;
use App\Sources\AzDo\AzDoClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

function injectAzDoReposClient(AzDoRepos $provider, AzDoClient $client): void
{
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($provider, $client);
}

it('exposes the azdo-repos id, display name, and dedicated credential fields', function () {
    $provider = new AzDoRepos(app(Vault::class));

    expect($provider->id())->toBe('azdo-repos')
        ->and($provider->displayName())->toBe('Azure DevOps Repos');

    $keys = array_map(fn ($field) => $field->key, $provider->credentialFields());

    expect($keys)->toBe(['azdo-repos.pat', 'azdo-repos.organization'])
        ->and($keys)->not->toContain('azdo.pat')
        ->and($keys)->not->toContain('azdo.organization');
});

it('tests azdo-repos connectivity successfully', function () {
    $http = new Client(['handler' => new MockHandler([
        new Response(200, [], '{"count":0,"value":[]}'),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    expect($provider->testConnection()->ok)->toBeTrue();
});

it('reports azdo-repos connection failure', function () {
    $http = new Client(['handler' => new MockHandler([
        new Response(401, [], 'unauthorized'),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    $result = $provider->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});

it('fails to build a client when the azdo-repos credential is not configured', function () {
    $provider = new AzDoRepos(app(Vault::class));

    $result = $provider->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('AzDO Repos PAT not configured');
});
