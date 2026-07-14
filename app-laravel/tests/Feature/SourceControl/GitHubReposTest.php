<?php

use App\Credentials\Vault;
use App\SourceControl\GitHub\GitHubRepos;
use App\Trackers\GitHub\GitHubClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

function injectGitHubReposClient(GitHubRepos $provider, GitHubClient $client): void
{
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($provider, $client);
}

it('exposes the github-repos id, display name, and dedicated credential field', function () {
    $provider = new GitHubRepos(app(Vault::class));

    expect($provider->id())->toBe('github-repos')
        ->and($provider->displayName())->toBe('GitHub Repos');

    $keys = array_map(fn ($field) => $field->key, $provider->credentialFields());

    expect($keys)->toBe(['github-repos.token'])
        ->and($keys)->not->toContain('github.token');
});

it('tests github-repos connectivity successfully', function () {
    $provider = new GitHubRepos(app(Vault::class));
    injectGitHubReposClient($provider, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => new MockHandler([
            new Response(200, [], json_encode(['login' => 'octocat'], JSON_THROW_ON_ERROR)),
        ])]),
    ));

    expect($provider->testConnection()->ok)->toBeTrue();
});

it('reports github-repos connection failure', function () {
    $provider = new GitHubRepos(app(Vault::class));
    injectGitHubReposClient($provider, new GitHubClient(
        'token',
        'https://api.github.com',
        new Client(['handler' => new MockHandler([
            new Response(401, [], '{"message":"Bad credentials"}'),
        ])]),
    ));

    $result = $provider->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});

it('fails to build a client when the github-repos credential is not configured', function () {
    $provider = new GitHubRepos(app(Vault::class));

    $result = $provider->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('Missing GitHub credential: github-repos.token');
});
