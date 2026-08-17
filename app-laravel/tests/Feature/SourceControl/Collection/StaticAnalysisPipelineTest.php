<?php

use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\LocalFinding;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sources\AzDo\AzDoClient;
use App\Sync\SystemIntegrationRuntime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Exercises the full chain this epic deliberately reuses unmodified: dispatch
 * -> enumerate -> batch -> clone/analyze -> Attachment -> AttachmentStored ->
 * ParseAttachmentIntoFindings -> SarifFindingParser -> LocalFinding.
 * Individual-component behavior (clone failure, one ecosystem's failure not
 * blocking the other, batch counts) is already covered by
 * AnalyzeRepositoryJobTest and DispatchStaticAnalysisRunsJobTest — this file
 * only covers the seams between this epic's code and the pre-existing,
 * unmodified pipeline, and the seam with Epic #341's own repository-collection
 * pipeline.
 */
function staticAnalysisPipelineFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/{$name}"));
}

function bindStaticAnalysisPipelineAzDoRepos(): void
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

/** @return list<string> */
function staticAnalysisPipelineCommandParts(mixed $command): array
{
    return is_array($command) ? $command : preg_split('/\s+/', (string) $command);
}

function staticAnalysisPipelineArgAfter(array $parts, string $flag): ?string
{
    $index = array_search($flag, $parts, true);

    return $index !== false ? ($parts[$index + 1] ?? null) : null;
}

/**
 * Handles every process this pipeline (and, where both run together,
 * Epic #341's repository-collection pipeline) shells out to: git clone
 * plants a trivial .sln and a pre-compiled .class file so both ecosystems
 * find something to analyze; trivy/roslynator/spotbugs each write realistic
 * fixture output to whatever --output/-output path they were given.
 */
function fakeStaticAnalysisPipelineProcesses(): void
{
    Process::fake(function ($process) {
        $parts = staticAnalysisPipelineCommandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $workDir = end($parts);
            File::ensureDirectoryExists($workDir . '/build');
            File::put($workDir . '/App.sln', '');
            File::put($workDir . '/build/Main.class', '');

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'trivy') {
            $outputPath = staticAnalysisPipelineArgAfter($parts, '--output');

            if ($outputPath !== null) {
                $joined = implode(' ', $parts);
                File::ensureDirectoryExists(dirname($outputPath));

                $content = match (true) {
                    str_contains($joined, 'cyclonedx') => staticAnalysisPipelineFixture('Trivy/cyclonedx-sample.json'),
                    in_array('secret', $parts, true) => staticAnalysisPipelineFixture('Trivy/secret-sarif-sample.json'),
                    default => staticAnalysisPipelineFixture('Trivy/vuln-sarif-sample.json'),
                };

                File::put($outputPath, $content);
            }

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(staticAnalysisPipelineArgAfter($parts, '--output'), staticAnalysisPipelineFixture('StaticAnalysis/roslynator-sample.json'));

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(staticAnalysisPipelineArgAfter($parts, '-output'), staticAnalysisPipelineFixture('StaticAnalysis/spotbugs-sample.json'));

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

beforeEach(function () {
    app(Vault::class)->set('azdo-repos.pat', null, 'fake-pat');
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');

    config(['repository_collection.trivy_server_url' => 'http://trivy-server:4954']);
    config(['repository_collection.trivy_token_file' => storage_path('app/private/static-analysis-pipeline-test-token')]);
    File::ensureDirectoryExists(storage_path('app/private'));
    File::put(storage_path('app/private/static-analysis-pipeline-test-token'), 'fake-token');

    fakeStaticAnalysisPipelineProcesses();
});

afterEach(function () {
    File::delete(storage_path('app/private/static-analysis-pipeline-test-token'));
});

it('produces LocalFinding rows through the existing, unmodified ingestion pipeline', function () {
    bindStaticAnalysisPipelineAzDoRepos();

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();
    expect($run->status)->toBe('success');

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    expect($system)->not->toBeNull();

    $container = SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->first();
    expect($container)->not->toBeNull();

    $attachments = Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->get();
    expect($attachments)->toHaveCount(2);

    // The fixture Roslynator SARIF payload carries one CA2100 result.
    expect(LocalFinding::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->where('kind', LocalFinding::KIND_CODE_QUALITY)->where('rule_id', 'CA2100')->exists())->toBeTrue();

    // The fixture SpotBugs SARIF payload carries one SQL_INJECTION_JDBC result.
    expect(LocalFinding::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->where('kind', LocalFinding::KIND_CODE_QUALITY)->where('rule_id', 'SQL_INJECTION_JDBC')->exists())->toBeTrue();
});

it('converges onto the same SecurityContainer as a repository-collection sweep of the same repository', function () {
    bindStaticAnalysisPipelineAzDoRepos();
    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class));

    bindStaticAnalysisPipelineAzDoRepos();
    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    expect($system)->not->toBeNull();
    expect(SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->count())->toBe(1);

    $container = SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->first();
    expect($container)->not->toBeNull();
    expect(SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->count())->toBe(1);

    $kinds = Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->pluck('kind')->sort()->values()->all();

    expect($kinds)->toBe(['code-quality-dotnet', 'code-quality-java', 'sbom', 'secrets', 'vulnerabilities']);

    expect(RepositoryCollectionRun::query()->latest('id')->first()->status)->toBe('success');
    expect(StaticAnalysisRun::query()->latest('id')->first()->status)->toBe('success');
});

it('reaches partial when one repository fails to clone but the ecosystems of another still both analyze', function () {
    Process::fake(function ($process) {
        $parts = staticAnalysisPipelineCommandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone' && str_contains(implode(' ', $parts), 'frontend-app')) {
            return Process::result(exitCode: 1, errorOutput: 'fatal: could not read from remote repository');
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $workDir = end($parts);
            File::ensureDirectoryExists($workDir . '/build');
            File::put($workDir . '/App.sln', '');
            File::put($workDir . '/build/Main.class', '');

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(staticAnalysisPipelineArgAfter($parts, '--output'), staticAnalysisPipelineFixture('StaticAnalysis/roslynator-sample.json'));
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(staticAnalysisPipelineArgAfter($parts, '-output'), staticAnalysisPipelineFixture('StaticAnalysis/spotbugs-sample.json'));
        }

        return Process::result(exitCode: 0);
    });

    $http = new Client(['handler' => new MockHandler([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], '{"count":2,"value":[
            {"id":"repo-001","name":"backend-api","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001","project":{"id":"project-001","name":"SecurityProject"},"defaultBranch":"refs/heads/main","remoteUrl":"https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api","webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/backend-api"},
            {"id":"repo-002","name":"frontend-app","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-002","project":{"id":"project-001","name":"SecurityProject"},"defaultBranch":"refs/heads/main","remoteUrl":"https://testorg@dev.azure.com/testorg/SecurityProject/_git/frontend-app","webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/frontend-app"}
        ]}'),
    ])]);
    $advSec = new Client(['handler' => new MockHandler([])]);
    $provider = new AzDoRepos(app(Vault::class));
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));
    app()->instance(AzDoRepos::class, $provider);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('partial')
        ->and($run->counts_json['repositories_considered'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(1);

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    $container = SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->first();

    expect(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(2);
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

    bindStaticAnalysisPipelineAzDoRepos();
    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $system->refresh();
    $container->refresh();

    expect($system->name)->toBe('Live-synced project name')
        ->and($system->description)->toBe('Live-synced description')
        ->and($container->name)->toBe('Live-synced repo name');

    expect(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(2);
});
