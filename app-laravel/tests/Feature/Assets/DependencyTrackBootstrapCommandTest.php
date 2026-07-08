<?php

use App\Assets\DependencyTrack\DependencyTrackAdminClient;
use App\Assets\DependencyTrack\DependencyTrackAdminClientFactory;
use App\Assets\DependencyTrack\DependencyTrackClient;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Credentials\Vault;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;

function bindFakeDependencyTrackAdminFactory(array &$history, array $responses): void
{
    app()->bind(DependencyTrackAdminClientFactory::class, function () use (&$history, $responses) {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new class($stack) extends DependencyTrackAdminClientFactory
        {
            public function __construct(private readonly HandlerStack $stack) {}

            public function make(string $baseUrl): DependencyTrackAdminClient
            {
                return new DependencyTrackAdminClient($baseUrl, new Client(['handler' => $this->stack]));
            }
        };
    });
}

function bindFakePingingDependencyTrackFactory(bool $pingSucceeds): void
{
    app()->bind(DependencyTrackClientFactory::class, function () use ($pingSucceeds) {
        $responses = $pingSucceeds
            ? [new Response(200, [], '{}')]
            : [new Response(401, [], '{}')];

        $stack = HandlerStack::create(new MockHandler($responses));

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

it('provisions the automation team and stores the api key in the vault', function () {
    bindFakePingingDependencyTrackFactory(false);

    $history = [];
    $teamsPayload = json_encode([[
        'uuid' => 'team-uuid',
        'name' => 'Automation',
        'permissions' => [['name' => 'BOM_UPLOAD']],
        'apiKeys' => [['publicId' => 'old-key']],
    ]], JSON_THROW_ON_ERROR);
    $newKeyPayload = json_encode(['key' => 'odt_fresh_key'], JSON_THROW_ON_ERROR);

    bindFakeDependencyTrackAdminFactory($history, [
        new Response(200, [], 'jwt-token'),          // login
        new Response(200, [], $teamsPayload),         // findOrCreateTeam
        new Response(200, [], ''),                    // grantPermission PROJECT_CREATION_UPLOAD
        new Response(200, [], $newKeyPayload),         // regenerateApiKey old-key
    ]);

    $this->artisan('dependencytrack:bootstrap')
        ->expectsOutputToContain('Dependency-Track bootstrap complete')
        ->assertSuccessful();

    expect(app(Vault::class)->get('dependencytrack.apiKey', null, true))->toBe('odt_fresh_key')
        ->and(app(Vault::class)->get('dependencytrack.baseUrl', null, true))->toBe('http://dependencytrack-apiserver:8080')
        ->and(app(Vault::class)->get('dependencytrack.adminPassword', null, true))->toBe('admin');

    expect($history[2]['request']->getMethod())->toBe('POST')
        ->and((string) $history[2]['request']->getUri())->toContain('permission/PROJECT_CREATION_UPLOAD/team/team-uuid');
    expect($history[3]['request']->getMethod())->toBe('POST')
        ->and((string) $history[3]['request']->getUri())->toContain('team/key/old-key');
});

it('handles a forced password change on first boot', function () {
    bindFakePingingDependencyTrackFactory(false);

    $history = [];
    $teamsPayload = json_encode([[
        'uuid' => 'team-uuid',
        'name' => 'Automation',
        'permissions' => [['name' => 'BOM_UPLOAD'], ['name' => 'PROJECT_CREATION_UPLOAD']],
        'apiKeys' => [],
    ]], JSON_THROW_ON_ERROR);

    bindFakeDependencyTrackAdminFactory($history, [
        new Response(401, [], 'FORCE_PASSWORD_CHANGE'), // initial login
        new Response(200, [], ''),                       // forceChangePassword
        new Response(200, [], 'jwt-token'),              // login again
        new Response(200, [], $teamsPayload),            // findOrCreateTeam
        new Response(200, [], json_encode(['key' => 'odt_fresh_key'], JSON_THROW_ON_ERROR)), // createApiKey
    ]);

    $this->artisan('dependencytrack:bootstrap')->assertSuccessful();

    expect(app(Vault::class)->get('dependencytrack.adminPassword', null, true))->not()->toBeNull()
        ->and(app(Vault::class)->get('dependencytrack.apiKey', null, true))->toBe('odt_fresh_key');
});

it('fails cleanly when the admin password is wrong and not a forced change', function () {
    bindFakePingingDependencyTrackFactory(false);

    $history = [];
    bindFakeDependencyTrackAdminFactory($history, [
        new Response(401, [], 'INVALID_CREDENTIALS'),
    ]);

    $this->artisan('dependencytrack:bootstrap', ['--admin-password' => 'wrong-password'])
        ->expectsOutputToContain('Could not log in to Dependency-Track as "admin": invalid credentials.')
        ->assertExitCode(1);
});

it('skips provisioning when the stored api key already works', function () {
    bindFakePingingDependencyTrackFactory(true);
    app(Vault::class)->set('dependencytrack.apiKey', null, 'already-valid-key');

    $history = [];
    bindFakeDependencyTrackAdminFactory($history, []);

    $this->artisan('dependencytrack:bootstrap')
        ->expectsOutputToContain('Dependency-Track is already configured; nothing to do.')
        ->assertSuccessful();

    expect($history)->toHaveCount(0);
});

it('configures the trivy analyzer when a token is provided', function () {
    bindFakePingingDependencyTrackFactory(false);

    $history = [];
    $teamsPayload = json_encode([[
        'uuid' => 'team-uuid',
        'name' => 'Automation',
        'permissions' => [['name' => 'BOM_UPLOAD'], ['name' => 'PROJECT_CREATION_UPLOAD']],
        'apiKeys' => [],
    ]], JSON_THROW_ON_ERROR);

    bindFakeDependencyTrackAdminFactory($history, [
        new Response(200, [], 'jwt-token'),                                              // login
        new Response(200, [], $teamsPayload),                                             // findOrCreateTeam
        new Response(200, [], json_encode(['key' => 'odt_fresh_key'], JSON_THROW_ON_ERROR)), // createApiKey
        new Response(200, [], ''),                                                        // trivy.enabled
        new Response(200, [], ''),                                                        // trivy.base.url
        new Response(200, [], ''),                                                        // trivy.api.token
    ]);

    $this->artisan('dependencytrack:bootstrap', ['--trivy-token' => 'trivy-shared-secret'])
        ->expectsOutputToContain('Configured Dependency-Track Trivy analyzer (base URL http://trivy-server:4954).')
        ->assertSuccessful();

    expect($history)->toHaveCount(6);

    $enabledBody = json_decode((string) $history[3]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect((string) $history[3]['request']->getUri())->toContain('api/v1/configProperty')
        ->and($enabledBody)->toBe([
            'groupName' => 'scanner',
            'propertyName' => 'trivy.enabled',
            'propertyValue' => 'true',
            'propertyType' => 'BOOLEAN',
        ]);

    $tokenBody = json_decode((string) $history[5]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($tokenBody)->toBe([
        'groupName' => 'scanner',
        'propertyName' => 'trivy.api.token',
        'propertyValue' => 'trivy-shared-secret',
        'propertyType' => 'ENCRYPTEDSTRING',
    ]);
});

it('reads the trivy token from a file when --trivy-token is not given', function () {
    bindFakePingingDependencyTrackFactory(false);

    $tokenFile = storage_path('app/testing/trivy-token');
    File::ensureDirectoryExists(dirname($tokenFile));
    File::put($tokenFile, "file-provided-secret\n");

    $history = [];
    $teamsPayload = json_encode([[
        'uuid' => 'team-uuid',
        'name' => 'Automation',
        'permissions' => [['name' => 'BOM_UPLOAD'], ['name' => 'PROJECT_CREATION_UPLOAD']],
        'apiKeys' => [],
    ]], JSON_THROW_ON_ERROR);

    bindFakeDependencyTrackAdminFactory($history, [
        new Response(200, [], 'jwt-token'),
        new Response(200, [], $teamsPayload),
        new Response(200, [], json_encode(['key' => 'odt_fresh_key'], JSON_THROW_ON_ERROR)),
        new Response(200, [], ''),
        new Response(200, [], ''),
        new Response(200, [], ''),
    ]);

    $this->artisan('dependencytrack:bootstrap', ['--trivy-token-file' => $tokenFile])
        ->assertSuccessful();

    $tokenBody = json_decode((string) $history[5]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($tokenBody['propertyValue'])->toBe('file-provided-secret');
});

it('warns when no trivy token or token file is provided', function () {
    bindFakePingingDependencyTrackFactory(false);

    $history = [];
    $teamsPayload = json_encode([[
        'uuid' => 'team-uuid',
        'name' => 'Automation',
        'permissions' => [['name' => 'BOM_UPLOAD'], ['name' => 'PROJECT_CREATION_UPLOAD']],
        'apiKeys' => [],
    ]], JSON_THROW_ON_ERROR);

    bindFakeDependencyTrackAdminFactory($history, [
        new Response(200, [], 'jwt-token'),
        new Response(200, [], $teamsPayload),
        new Response(200, [], json_encode(['key' => 'odt_fresh_key'], JSON_THROW_ON_ERROR)),
    ]);

    $this->artisan('dependencytrack:bootstrap')
        ->expectsOutputToContain('Skipping Dependency-Track Trivy analyzer configuration')
        ->assertSuccessful();

    expect($history)->toHaveCount(3);
});
