<?php

use App\Credentials\Vault;
use App\Models\SecurityEvent;
use App\Sources\AzDo\AzDoClient;
use App\Sync\EnrichAzDoSecretJob;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

it('patches validityDetails and validationFingerprints into the event metadata', function () {
    $event = SecurityEvent::factory()->secret()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3001',
        'fingerprint' => null,
        'metadata' => ['sourceProjectId' => 'proj-1', 'sourceRepoId' => 'repo-1'],
    ]);

    $alertDetail = json_encode([
        'alertId' => 3001,
        'alertType' => 'secret',
        'severity' => 'critical',
        'state' => 'active',
        'title' => 'GitHub PAT detected',
        'validityDetails' => [
            'validityStatus' => 'active',
            'validityLastUpdatedDate' => '2026-06-01T00:00:00Z',
            'message' => 'Secret is still active.',
        ],
        'validationFingerprints' => [
            [
                'validationFingerprintHash' => 'sha256:abc123fingerprint',
                'validityResult' => 'active',
                'validityLastUpdatedDate' => '2026-06-01T00:00:00Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $http = new Client(['handler' => new MockHandler]);
    $advSec = new Client(['handler' => new MockHandler([new Response(200, [], $alertDetail)])]);
    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);

    $vault = Mockery::mock(Vault::class);

    $job = new EnrichAzDoSecretJob('azdo', $event->id, 'proj-1', 'repo-1', 3001);
    $job->handle($vault, $client);

    $event->refresh();

    expect($event->metadata)->toBeArray()
        ->and($event->metadata['validityDetails']['validityStatus'])->toBe('active')
        ->and($event->metadata['validationFingerprints'])->toHaveCount(1)
        ->and($event->metadata['validationFingerprints'][0]['validationFingerprintHash'])->toBe('sha256:abc123fingerprint')
        ->and($event->fingerprint)->toBe('sha256:abc123fingerprint');
});

it('sets fingerprint only when the event has none yet', function () {
    $event = SecurityEvent::factory()->secret()->create([
        'source_id' => 'azdo',
        'source_event_id' => '3002',
        'fingerprint' => 'existing-fingerprint',
        'metadata' => ['sourceProjectId' => 'proj-1', 'sourceRepoId' => 'repo-1'],
    ]);

    $alertDetail = json_encode([
        'alertId' => 3002,
        'alertType' => 'secret',
        'severity' => 'critical',
        'state' => 'active',
        'title' => 'GitHub PAT detected',
        'validityDetails' => ['validityStatus' => 'active'],
        'validationFingerprints' => [
            ['validationFingerprintHash' => 'sha256:newfingerprint'],
        ],
    ], JSON_THROW_ON_ERROR);

    $http = new Client(['handler' => new MockHandler]);
    $advSec = new Client(['handler' => new MockHandler([new Response(200, [], $alertDetail)])]);
    $client = new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec);

    $vault = Mockery::mock(Vault::class);

    (new EnrichAzDoSecretJob('azdo', $event->id, 'proj-1', 'repo-1', 3002))->handle($vault, $client);

    $event->refresh();

    // Fingerprint must not be overwritten
    expect($event->fingerprint)->toBe('existing-fingerprint')
        ->and($event->metadata['validationFingerprints'][0]['validationFingerprintHash'])->toBe('sha256:newfingerprint');
});

it('silently exits when the event has been deleted before the job runs', function () {
    $vault = Mockery::mock(Vault::class);
    // No vault::get calls expected — job exits early

    expect(fn () => (new EnrichAzDoSecretJob('azdo', 999999, 'proj-1', 'repo-1', 3001))->handle($vault))
        ->not->toThrow(Throwable::class);
});
