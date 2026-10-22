<?php

use App\Assets\AttachmentService;
use App\Assets\DependencyTrack\DependencyTrackClient;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Credentials\Vault;
use App\Events\AttachmentStored;
use App\Listeners\PushSbomAttachmentToDependencyTrack;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function bindFakeDependencyTrackFactoryForAttachmentPushTest(array &$history): void
{
    app()->bind(DependencyTrackClientFactory::class, function () use (&$history) {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
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

it('pushes a newly stored sbom attachment straight to dependency-track', function () {
    $history = [];
    bindFakeDependencyTrackFactoryForAttachmentPushTest($history);
    app(Vault::class)->set('dependencytrack.apiKey', null, 'vault-key');
    app(Vault::class)->set('dependencytrack.baseUrl', null, 'http://dtrack.internal:9010');

    $container = SecurityContainer::factory()->create(['name' => 'backend-api']);

    app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json')),
    );

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('X-Api-Key'))->toBe('vault-key');

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body['projectName'])->toBe('backend-api')
        ->and($body['projectVersion'])->toBe('latest');
});

it('fails loudly instead of skipping when no dependency-track api key is configured', function () {
    $history = [];
    bindFakeDependencyTrackFactoryForAttachmentPushTest($history);

    $container = SecurityContainer::factory()->create(['name' => 'unconfigured-repo']);

    // The sync test queue runs the queued listener inline, so its failure surfaces here; on the
    // real queue it is a failed job, and the attachment itself is already committed either way.
    expect(fn () => app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json')),
    ))->toThrow(RuntimeException::class, 'Dependency-Track API key is not configured; run dependencytrack:bootstrap or set it under System Credentials.');

    expect($history)->toHaveCount(0)
        ->and($container->attachments()->where('kind', 'sbom')->count())->toBe(1);
});

it('does not require a dependency-track api key for non-sbom attachments', function () {
    $history = [];
    bindFakeDependencyTrackFactoryForAttachmentPushTest($history);

    $container = SecurityContainer::factory()->create(['name' => 'backend-api']);

    app(AttachmentService::class)->attachTo(
        $container,
        'vulnerabilities',
        'application/json',
        'vuln.sarif.json',
        (string) file_get_contents(base_path('tests/Fixtures/Trivy/vuln-sarif-sample.json')),
    );

    expect($history)->toHaveCount(0);
});

it('does not push non-sbom attachments to dependency-track', function () {
    $history = [];
    bindFakeDependencyTrackFactoryForAttachmentPushTest($history);
    app(Vault::class)->set('dependencytrack.apiKey', null, 'vault-key');

    $container = SecurityContainer::factory()->create(['name' => 'backend-api']);

    app(AttachmentService::class)->attachTo(
        $container,
        'vulnerabilities',
        'application/json',
        'vuln.sarif.json',
        (string) file_get_contents(base_path('tests/Fixtures/Trivy/vuln-sarif-sample.json')),
    );

    expect($history)->toHaveCount(0);
});

it('does not push sbom attachments belonging to a non-container owner', function () {
    // Exercises the listener directly rather than through AttachmentService::attachTo(),
    // since SBOM ingestion for a SoftwareSystem/SoftwareAsset owner is a separate, pre-existing
    // concern (those relations aren't polymorphic the way SecurityContainer's is) unrelated to
    // what this test verifies: the DT-push listener's owner-type guard.
    $history = [];
    bindFakeDependencyTrackFactoryForAttachmentPushTest($history);
    app(Vault::class)->set('dependencytrack.apiKey', null, 'vault-key');

    $system = SoftwareSystem::factory()->create();
    $attachment = $system->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom.cdx.json',
        'payload' => (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json')),
        'size_bytes' => 2,
        'created_at' => now(),
    ]);

    app(PushSbomAttachmentToDependencyTrack::class)->handle(new AttachmentStored($attachment));

    expect($history)->toHaveCount(0);
});

it('pushes the merged SBOM when a committed-archive SBOM is stored', function () {
    $history = [];
    bindFakeDependencyTrackFactoryForAttachmentPushTest($history);
    app(Vault::class)->set('dependencytrack.apiKey', null, 'vault-key');

    $container = SecurityContainer::factory()->create(['name' => 'Phoenix-JBoss']);
    $container->attachments()->create([
        'kind' => 'sbom',
        'mime' => 'application/json',
        'name' => 'sbom.cdx.json',
        'payload' => (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json')),
        'size_bytes' => 2,
        'created_at' => now()->subMinute(),
    ]);

    app(AttachmentService::class)->attachTo(
        $container,
        'sbom-archives',
        'application/json',
        'archives.cdx.json',
        json_encode(['bomFormat' => 'CycloneDX', 'specVersion' => '1.6', 'components' => [[
            'bom-ref' => 'struts', 'type' => 'library', 'name' => 'struts2-core', 'version' => '2.3.16.3', 'purl' => 'pkg:maven/org.apache.struts/struts2-core@2.3.16.3',
        ]]], JSON_THROW_ON_ERROR),
    );

    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $purls = array_column(json_decode((string) base64_decode((string) $body['bom'], true), true, 512, JSON_THROW_ON_ERROR)['components'], 'purl');

    expect($history)->toHaveCount(1)
        ->and($purls)->toContain('pkg:maven/org.apache.struts/struts2-core@2.3.16.3')
        ->and(count($purls))->toBeGreaterThan(1);
});
