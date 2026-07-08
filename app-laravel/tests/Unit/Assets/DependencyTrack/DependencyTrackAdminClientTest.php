<?php

use App\Assets\DependencyTrack\DependencyTrackAdminClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function dtrackAdminClient(array $responses, array &$history): DependencyTrackAdminClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new DependencyTrackAdminClient('http://dependencytrack-apiserver:8080', new Client(['handler' => $stack]));
}

it('returns the jwt on successful login', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(200, [], 'jwt-token')], $history);

    $token = $client->login('admin', 'admin');

    expect($token)->toBe('jwt-token');

    $request = $history[0]['request'];
    parse_str((string) $request->getBody(), $formParams);

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toContain('api/v1/user/login')
        ->and($formParams)->toBe(['username' => 'admin', 'password' => 'admin']);
});

it('returns null when dependency-track requires a forced password change', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(401, [], 'FORCE_PASSWORD_CHANGE')], $history);

    expect($client->login('admin', 'admin'))->toBeNull();
});

it('rethrows other login failures', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(401, [], 'INVALID_CREDENTIALS')], $history);

    expect(fn () => $client->login('admin', 'wrong'))
        ->toThrow(ClientException::class);
});

it('sends the expected force change password request', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(200, [], '')], $history);

    $client->forceChangePassword('admin', 'admin', 'new-secret-password');

    $request = $history[0]['request'];
    parse_str((string) $request->getBody(), $formParams);

    expect($formParams)->toBe([
        'username' => 'admin',
        'password' => 'admin',
        'newPassword' => 'new-secret-password',
        'confirmPassword' => 'new-secret-password',
    ]);
});

it('finds an existing team by name and normalizes permissions and api keys', function () {
    $history = [];
    $teamsPayload = json_encode([
        [
            'uuid' => 'team-uuid',
            'name' => 'Automation',
            'permissions' => [['name' => 'BOM_UPLOAD']],
            'apiKeys' => [['publicId' => 'key-1']],
        ],
    ], JSON_THROW_ON_ERROR);
    $client = dtrackAdminClient([new Response(200, [], $teamsPayload)], $history);

    $team = $client->findOrCreateTeam('jwt-token', 'Automation');

    expect($team)->toBe([
        'uuid' => 'team-uuid',
        'permissions' => ['BOM_UPLOAD'],
        'apiKeyPublicIds' => ['key-1'],
    ]);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer jwt-token');
});

it('creates a team when none matches the given name', function () {
    $history = [];
    $createdPayload = json_encode([
        'uuid' => 'new-team-uuid',
        'permissions' => [],
        'apiKeys' => [],
    ], JSON_THROW_ON_ERROR);
    $client = dtrackAdminClient([
        new Response(200, [], '[]'),
        new Response(200, [], $createdPayload),
    ], $history);

    $team = $client->findOrCreateTeam('jwt-token', 'Automation');

    expect($team)->toBe(['uuid' => 'new-team-uuid', 'permissions' => [], 'apiKeyPublicIds' => []]);

    expect($history[1]['request']->getMethod())->toBe('PUT')
        ->and((string) $history[1]['request']->getUri())->toContain('api/v1/team');

    $body = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body)->toBe(['name' => 'Automation']);
});

it('grants a permission to a team', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(200, [], '')], $history);

    $client->grantPermission('jwt-token', 'PROJECT_CREATION_UPLOAD', 'team-uuid');

    expect($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toContain('api/v1/permission/PROJECT_CREATION_UPLOAD/team/team-uuid');
});

it('deletes an existing api key', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(204, [], '')], $history);

    $client->deleteApiKey('jwt-token', 'key-1');

    expect($history[0]['request']->getMethod())->toBe('DELETE')
        ->and((string) $history[0]['request']->getUri())->toContain('api/v1/team/key/key-1');
});

it('creates a new api key and returns the raw secret', function () {
    $history = [];
    $payload = json_encode(['key' => 'odt_new_secret_key'], JSON_THROW_ON_ERROR);
    $client = dtrackAdminClient([new Response(200, [], $payload)], $history);

    $key = $client->createApiKey('jwt-token', 'team-uuid');

    expect($key)->toBe('odt_new_secret_key');
    expect((string) $history[0]['request']->getUri())->toContain('api/v1/team/team-uuid/key');
});

it('regenerates an existing api key by public id and returns the new secret', function () {
    $history = [];
    $payload = json_encode(['key' => 'odt_rotated_secret_key'], JSON_THROW_ON_ERROR);
    $client = dtrackAdminClient([new Response(200, [], $payload)], $history);

    $key = $client->regenerateApiKey('jwt-token', 'key-1');

    expect($key)->toBe('odt_rotated_secret_key');
    expect($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())->toContain('api/v1/team/key/key-1');
});

it('sets a config property', function () {
    $history = [];
    $client = dtrackAdminClient([new Response(200, [], '')], $history);

    $client->setConfigProperty('jwt-token', 'scanner', 'trivy.base.url', 'http://trivy-server:4954', 'URL');

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toContain('api/v1/configProperty')
        ->and($body)->toBe([
            'groupName' => 'scanner',
            'propertyName' => 'trivy.base.url',
            'propertyValue' => 'http://trivy-server:4954',
            'propertyType' => 'URL',
        ]);
});
