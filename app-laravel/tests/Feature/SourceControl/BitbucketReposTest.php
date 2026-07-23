<?php

use App\Credentials\Vault;
use App\SourceControl\Bitbucket\BitbucketClient;
use App\SourceControl\Bitbucket\BitbucketRepos;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Registry;
use App\Sources\Context\SourceContextFacts;
use App\Sources\Dto\SystemDto;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

function injectBitbucketReposClient(BitbucketRepos $provider, BitbucketClient $client): void
{
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($provider, $client);
}

function bitbucketClientReturning(string $body): BitbucketClient
{
    $http = new Client(['handler' => new MockHandler([new Response(200, [], $body)])]);

    return new BitbucketClient('myworkspace', 'test-token', 'https://api.bitbucket.org/2.0', $http);
}

it('exposes the bitbucket-repos id, display name, and dedicated credential fields', function () {
    $provider = new BitbucketRepos(app(Vault::class));

    expect($provider->id())->toBe('bitbucket-repos')
        ->and($provider->displayName())->toBe('Bitbucket');

    $keys = array_map(fn ($field) => $field->key, $provider->credentialFields());

    expect($keys)->toBe(['bitbucket-repos.token', 'bitbucket-repos.workspace'])
        ->and($keys)->not->toContain('jira.token');
});

it('registers bitbucket-repos in the source control registry alongside azdo and github', function () {
    $ids = array_map(fn ($provider): string => $provider->id(), app(Registry::class)->all());

    expect($ids)->toContain('bitbucket-repos')
        ->and($ids)->toContain('azdo-repos')
        ->and($ids)->toContain('github-repos');
});

it('tests bitbucket-repos connectivity successfully', function () {
    $provider = new BitbucketRepos(app(Vault::class));
    injectBitbucketReposClient($provider, bitbucketClientReturning('{"values":[]}'));

    expect($provider->testConnection()->ok)->toBeTrue();
});

it('reports bitbucket-repos connection failure', function () {
    $http = new Client(['handler' => new MockHandler([new Response(401, [], 'unauthorized')])]);

    $provider = new BitbucketRepos(app(Vault::class));
    injectBitbucketReposClient($provider, new BitbucketClient('myworkspace', 'test-token', 'https://api.bitbucket.org/2.0', $http));

    $result = $provider->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});

it('fails to build a client when the bitbucket-repos credential is not configured', function () {
    $provider = new BitbucketRepos(app(Vault::class));

    $result = $provider->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('bitbucket-repos.token');
});

it('models the whole workspace as a single system', function () {
    $provider = new BitbucketRepos(app(Vault::class));
    injectBitbucketReposClient($provider, bitbucketClientReturning('{"values":[]}'));

    expect($provider)->toBeInstanceOf(EnumeratesInventory::class);

    $systems = iterator_to_array($provider->fetchProjects());

    expect($systems)->toHaveCount(1)
        ->and($systems[0])->toBeInstanceOf(SystemDto::class)
        ->and($systems[0]->sourceSystemId)->toBe('myworkspace')
        ->and($systems[0]->name)->toBe('myworkspace')
        ->and($systems[0]->url)->toBe('https://bitbucket.org/myworkspace');
});

it('enumerates workspace repositories as repository containers', function () {
    $body = json_encode([
        'values' => [[
            'slug' => 'backend-api',
            'name' => 'backend-api',
            'full_name' => 'myworkspace/backend-api',
            'mainbranch' => ['name' => 'main'],
            'links' => ['html' => ['href' => 'https://bitbucket.org/myworkspace/backend-api']],
        ]],
    ], JSON_THROW_ON_ERROR);

    $provider = new BitbucketRepos(app(Vault::class));
    injectBitbucketReposClient($provider, bitbucketClientReturning($body));

    $project = new SystemDto(sourceSystemId: 'myworkspace', name: 'myworkspace');
    $repos = iterator_to_array($provider->fetchRepositories($project));

    expect($repos)->toHaveCount(1)
        ->and($repos[0]->sourceContainerId)->toBe('backend-api')
        ->and($repos[0]->name)->toBe('backend-api')
        ->and($repos[0]->sourceSystemId)->toBe('myworkspace')
        ->and($repos[0]->kind)->toBe('repository')
        ->and($repos[0]->url)->toBe('https://bitbucket.org/myworkspace/backend-api')
        ->and(SourceContextFacts::getString($repos[0]->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('main')
        ->and(SourceContextFacts::getString($repos[0]->metadata ?? [], SourceContextFacts::SOURCE_PROVIDER))->toBe('bitbucket');
});
