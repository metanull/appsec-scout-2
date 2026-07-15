<?php

use App\Credentials\Vault;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\Sources\AzDo\AzDoClient;
use App\Sources\Dto\SystemDto;
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

function azdoReposFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/AzDo/{$name}"));
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

it('implements EnumeratesInventory and enumerates projects using its own AzDO Repos client', function () {
    $http = new Client(['handler' => new MockHandler([
        new Response(200, [], azdoReposFixture('projects.json')),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    expect($provider)->toBeInstanceOf(EnumeratesInventory::class);

    $projects = iterator_to_array($provider->fetchProjects());

    expect($projects)->toHaveCount(2)
        ->and($projects[0])->toBeInstanceOf(SystemDto::class)
        ->and($projects[0]->sourceSystemId)->toBe('project-001')
        ->and($projects[0]->name)->toBe('SecurityProject');
});

it('enumerates repositories for a project, filling in the project id as sourceSystemId', function () {
    $http = new Client(['handler' => new MockHandler([
        new Response(200, [], azdoReposFixture('repositories.json')),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    $project = new SystemDto(sourceSystemId: 'project-001', name: 'SecurityProject');
    $repos = iterator_to_array($provider->fetchRepositories($project));

    expect($repos)->toHaveCount(2)
        ->and($repos[0]->sourceContainerId)->toBe('repo-001')
        ->and($repos[0]->name)->toBe('backend-api')
        ->and($repos[0]->sourceSystemId)->toBe('project-001')
        ->and($repos[0]->kind)->toBe('repository');
});
