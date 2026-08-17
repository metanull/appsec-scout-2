<?php

use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\LocalFinding;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\Sources\AzDo\AzDoClient;
use App\Sync\SystemIntegrationRuntime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Exercises the full chain this epic deliberately reuses unmodified: dispatch
 * -> enumerate -> batch -> clone/scan -> Attachment -> AttachmentStored ->
 * ParseAttachmentIntoFindings -> CycloneDxSbomParser/SarifFindingParser ->
 * SoftwareComponent/LocalFinding. Individual-component behavior (clone
 * failure throws, one Trivy scan failing doesn't block the others, batch
 * counts) is already covered by CollectRepositoryJobTest and
 * DispatchRepositoryCollectionRunsJobTest — this file only covers the seams
 * between this epic's code and the pre-existing, unmodified pipeline.
 */
function pipelineFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/Trivy/{$name}"));
}

function bindPipelineAzDoRepos(): void
{
    $http = new Client(['handler' => new MockHandler([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], '{"count":1,"value":[{"id":"repo-001","name":"backend-api","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001","project":{"id":"project-001","name":"SecurityProject"},"defaultBranch":"refs/heads/main","remoteUrl":"https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api","webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/backend-api"}]}'),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));

    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    app()->instance(AzDoRepos::class, $provider);
}

function fakeRealTrivyOutput(): void
{
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'trivy') {
            $outputIndex = array_search('--output', $parts, true);
            $outputPath = $outputIndex !== false ? $parts[$outputIndex + 1] : null;

            if ($outputPath !== null) {
                $joined = implode(' ', $parts);
                File::ensureDirectoryExists(dirname($outputPath));

                $content = match (true) {
                    str_contains($joined, 'cyclonedx') => pipelineFixture('cyclonedx-sample.json'),
                    in_array('secret', $parts, true) => pipelineFixture('secret-sarif-sample.json'),
                    default => pipelineFixture('vuln-sarif-sample.json'),
                };

                File::put($outputPath, $content);
            }
        }

        return Process::result(exitCode: 0);
    });
}

beforeEach(function () {
    app(Vault::class)->set('azdo-repos.pat', null, 'fake-pat');
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');

    config(['repository_collection.trivy_server_url' => 'http://trivy-server:4954']);
    config(['repository_collection.trivy_token_file' => storage_path('app/private/pipeline-test-token')]);
    File::ensureDirectoryExists(storage_path('app/private'));
    File::put(storage_path('app/private/pipeline-test-token'), 'fake-token');

    fakeRealTrivyOutput();
});

afterEach(function () {
    File::delete(storage_path('app/private/pipeline-test-token'));
});

it('produces SoftwareComponent and LocalFinding rows through the existing, unmodified ingestion pipeline', function () {
    bindPipelineAzDoRepos();

    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class), app(Recorder::class));

    $run = RepositoryCollectionRun::query()->latest('id')->first();
    expect($run->status)->toBe('success');

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    expect($system)->not->toBeNull();

    $container = SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->first();
    expect($container)->not->toBeNull();

    expect(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(3);

    // The fixture CycloneDX payload carries several `purl`-bearing components.
    expect(SoftwareComponent::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBeGreaterThan(0);

    // The fixture vuln SARIF payload carries one CVE-2024-56201 result on Jinja2.
    expect(LocalFinding::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->where('kind', LocalFinding::KIND_VULNERABILITY)->exists())->toBeTrue();

    // The fixture secret SARIF payload carries at least one secret result.
    expect(LocalFinding::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->where('kind', LocalFinding::KIND_SECRET)->exists())->toBeTrue();
});

it('does not create a duplicate SoftwareSystem/SecurityContainer when the same repository is swept twice', function () {
    bindPipelineAzDoRepos();
    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class), app(Recorder::class));

    bindPipelineAzDoRepos();
    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class), app(Recorder::class));

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->count())->toBe(1);

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    expect(SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->count())->toBe(1);
});

it('never overwrites a SoftwareSystem/SecurityContainer already created by a live AzDO sync', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'project-001',
        'name' => 'Live-synced project name',
        'description' => 'Live-synced description',
    ]);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
        'name' => 'Live-synced repo name',
    ]);

    bindPipelineAzDoRepos();
    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class), app(Recorder::class));

    $system->refresh();
    $container->refresh();

    expect($system->name)->toBe('Live-synced project name')
        ->and($system->description)->toBe('Live-synced description')
        ->and($container->name)->toBe('Live-synced repo name');

    expect(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(3);
});
