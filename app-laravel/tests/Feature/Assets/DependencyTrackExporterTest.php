<?php

use App\Assets\DependencyTrack\DependencyTrackClient;
use App\Assets\DependencyTrack\DependencyTrackExporter;
use App\Models\SecurityContainer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

it('uploads the latest stored sbom attachment for the container', function () {
    $container = SecurityContainer::factory()->create(['name' => 'backend-api']);

    $container->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom-old.json',
        'payload' => '{"bomFormat":"CycloneDX","serialNumber":"old"}',
        'size_bytes' => 10,
        'created_at' => now()->subDay(),
    ]);

    $latest = $container->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom-latest.json',
        'payload' => '{"bomFormat":"CycloneDX","serialNumber":"latest"}',
        'size_bytes' => 10,
        'created_at' => now(),
    ]);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));

    $client = new DependencyTrackClient('api-key', 'http://dependencytrack-apiserver:8080', new Client(['handler' => $stack]));
    $exporter = new DependencyTrackExporter($client);

    $exporter->export($container, 'latest');

    expect($history)->toHaveCount(1);

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($body['projectName'])->toBe('backend-api')
        ->and($body['projectVersion'])->toBe('latest')
        ->and(base64_decode((string) $body['bom'], true))->toBe($latest->payload);
});

it('downgrades an unsupported specVersion 1.7 payload to 1.6 before uploading', function () {
    $container = SecurityContainer::factory()->create(['name' => 'newer-trivy-repo']);

    $container->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom.json',
        'payload' => json_encode(['bomFormat' => 'CycloneDX', 'specVersion' => '1.7', 'components' => []], JSON_THROW_ON_ERROR),
        'size_bytes' => 10,
        'created_at' => now(),
    ]);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));

    $client = new DependencyTrackClient('api-key', 'http://dependencytrack-apiserver:8080', new Client(['handler' => $stack]));
    $exporter = new DependencyTrackExporter($client);

    $exporter->export($container, 'latest');

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $uploadedBom = json_decode((string) base64_decode((string) $body['bom'], true), true, 512, JSON_THROW_ON_ERROR);

    expect($uploadedBom['specVersion'])->toBe('1.6')
        ->and($uploadedBom['bomFormat'])->toBe('CycloneDX');
});

it('throws when the container has no sbom attachment', function () {
    $container = SecurityContainer::factory()->create(['name' => 'no-sbom-repo']);

    $client = new DependencyTrackClient('api-key', 'http://dependencytrack-apiserver:8080', new Client([
        'handler' => new MockHandler([]),
    ]));
    $exporter = new DependencyTrackExporter($client);

    expect(fn () => $exporter->export($container, 'latest'))
        ->toThrow(RuntimeException::class, 'No SBOM attachment found for container "no-sbom-repo".');
});
