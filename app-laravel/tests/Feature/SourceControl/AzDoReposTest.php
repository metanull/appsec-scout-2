<?php

use App\Credentials\Vault;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\Sources\AzDo\AzDoClient;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

    $result = app(Vault::class)->runAsOwner(null, fn () => $provider->testConnection());

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});

it('fails to build a client when the azdo-repos credential is not configured', function () {
    $provider = new AzDoRepos(app(Vault::class));

    $result = app(Vault::class)->runAsOwner(null, fn () => $provider->testConnection());

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

it('enumerates disabled repositories too, flagged as disabled', function () {
    $http = new Client(['handler' => new MockHandler([
        new Response(200, [], azdoReposFixture('repositories-with-disabled.json')),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    $project = new SystemDto(sourceSystemId: 'project-001', name: 'SecurityProject');
    $repos = iterator_to_array($provider->fetchRepositories($project));

    expect(array_map(fn ($r) => [$r->sourceContainerId, $r->isDisabled], $repos))
        ->toBe([['repo-001', false], ['repo-002', false], ['repo-003', true]]);
});

it('reports the newest commit on the default branch, with its committer date and author', function () {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['count' => 1, 'value' => [[
            'commitId' => 'abc123def456',
            'author' => ['name' => 'Ada Author', 'date' => '2026-08-10T09:00:00Z'],
            'committer' => ['name' => 'Cory Committer', 'date' => '2026-08-13T06:06:13Z'],
        ]]])),
    ]));
    $handler->push(Middleware::history($history));
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', new Client(['handler' => $handler]), $advSec));

    $project = new SystemDto(sourceSystemId: 'project-001', name: 'SecurityProject');
    $repository = new ContainerDto('repo-001', 'backend-api', 'project-001', 'repository', metadata: ['code' => ['default_branch' => 'master']]);

    $commit = $provider->fetchLastCommit($project, $repository);
    parse_str($history[0]['request']->getUri()->getQuery(), $query);

    expect($commit)->not->toBeNull()
        ->and($commit->sha)->toBe('abc123def456')
        ->and($commit->committedAt->toIso8601ZuluString())->toBe('2026-08-13T06:06:13Z')
        ->and($commit->author)->toBe('Ada Author')
        ->and($history[0]['request']->getUri()->getPath())->toEndWith('project-001/_apis/git/repositories/repo-001/commits')
        // parse_str() turns the query's dots into underscores.
        ->and($query['searchCriteria_itemVersion_version'])->toBe('master');
});

it('reports no last commit for an empty repository whose default branch does not resolve', function () {
    $http = new Client(['handler' => new MockHandler([
        new Response(404, [], '{"message":"TF401175: The version descriptor could not be resolved"}'),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    $project = new SystemDto(sourceSystemId: 'project-001', name: 'SecurityProject');
    $repository = new ContainerDto('repo-001', 'empty-repo', 'project-001', 'repository', metadata: ['code' => ['default_branch' => 'main']]);

    expect($provider->fetchLastCommit($project, $repository))->toBeNull();
});

it('does not query commits for a disabled repository', function () {
    // An empty mock queue: any HTTP call would throw.
    $http = new Client(['handler' => new MockHandler([])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));
    injectAzDoReposClient($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    $project = new SystemDto(sourceSystemId: 'project-001', name: 'SecurityProject');
    $repository = new ContainerDto('repo-003', 'archived-service', 'project-001', 'repository', metadata: ['code' => ['default_branch' => 'main']], isDisabled: true);

    expect($provider->fetchLastCommit($project, $repository))->toBeNull();
});
