<?php

use App\Assets\DependencyTrack\DependencyTrackClient;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Credentials\Vault;
use App\Models\SecurityContainer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function bindFakeDependencyTrackFactory(array &$history, ?array $responses = null): void
{
    $responses ??= [new Response(200, [], '{}'), new Response(200, [], '{}')];

    app()->bind(DependencyTrackClientFactory::class, function () use (&$history, $responses) {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new class($stack) extends DependencyTrackClientFactory
        {
            public function __construct(private readonly HandlerStack $stack) {}

            public function make(string $apiKey, string $baseUrl): DependencyTrackClient
            {
                return new DependencyTrackClient($apiKey, $baseUrl, new Client(['handler' => $this->stack]));
            }
        };
    });
}

it('uploads the latest sbom for every container that has one', function () {
    $history = [];
    bindFakeDependencyTrackFactory($history);

    $withSbom = SecurityContainer::factory()->create(['name' => 'backend-api']);
    $withSbom->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom.json',
        'payload' => '{"bomFormat":"CycloneDX"}',
        'size_bytes' => 10,
        'created_at' => now(),
    ]);

    $withoutSbom = SecurityContainer::factory()->create(['name' => 'no-sbom-repo']);

    $this->artisan('sbom:export-dependency-track', [
        '--api-key' => 'dtrack-key',
    ])
        ->expectsOutputToContain('Uploaded SBOM for 1 container(s) to Dependency-Track.')
        ->assertSuccessful();

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('X-Api-Key'))->toBe('dtrack-key');

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body['projectName'])->toBe('backend-api')
        ->and($body['projectVersion'])->toBe('latest');
});

it('scopes the export to a single container id when provided', function () {
    $history = [];
    bindFakeDependencyTrackFactory($history);

    $first = SecurityContainer::factory()->create(['name' => 'first-repo']);
    $first->attachments()->create([
        'kind' => 'sbom', 'mime' => 'application/json', 'name' => 'sbom.json',
        'payload' => '{}', 'size_bytes' => 2, 'created_at' => now(),
    ]);

    $second = SecurityContainer::factory()->create(['name' => 'second-repo']);
    $second->attachments()->create([
        'kind' => 'sbom', 'mime' => 'application/json', 'name' => 'sbom.json',
        'payload' => '{}', 'size_bytes' => 2, 'created_at' => now(),
    ]);

    $this->artisan('sbom:export-dependency-track', [
        '--api-key' => 'dtrack-key',
        '--container' => (string) $first->id,
        '--project-version' => '1.2.3',
    ])->assertSuccessful();

    expect($history)->toHaveCount(1);

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body['projectName'])->toBe('first-repo')
        ->and($body['projectVersion'])->toBe('1.2.3');
});

it('fails when no containers have a stored sbom attachment', function () {
    $history = [];
    bindFakeDependencyTrackFactory($history);

    SecurityContainer::factory()->create(['name' => 'no-sbom-repo']);

    $this->artisan('sbom:export-dependency-track', ['--api-key' => 'dtrack-key'])
        ->expectsOutputToContain('No security containers with a stored SBOM attachment were found.')
        ->assertExitCode(1);

    expect($history)->toHaveCount(0);
});

it('falls back to the vault-stored api key and base url when no option is given', function () {
    $history = [];
    bindFakeDependencyTrackFactory($history);

    app(Vault::class)->set('dependencytrack.apiKey', null, 'vault-key');
    app(Vault::class)->set('dependencytrack.baseUrl', null, 'http://dtrack.internal:9010');

    $container = SecurityContainer::factory()->create(['name' => 'vault-configured-repo']);
    $container->attachments()->create([
        'kind' => 'sbom', 'mime' => 'application/json', 'name' => 'sbom.json',
        'payload' => '{}', 'size_bytes' => 2, 'created_at' => now(),
    ]);

    $this->artisan('sbom:export-dependency-track')->assertSuccessful();

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('X-Api-Key'))->toBe('vault-key');
});

it('fails with a clear message when no api key is configured anywhere', function () {
    $history = [];
    bindFakeDependencyTrackFactory($history);

    SecurityContainer::factory()->create(['name' => 'unconfigured-repo']);

    $this->artisan('sbom:export-dependency-track')
        ->expectsOutputToContain('Dependency-Track API key is not configured. Run `php artisan dependencytrack:bootstrap` first, or pass --api-key.')
        ->assertExitCode(1);

    expect($history)->toHaveCount(0);
});
