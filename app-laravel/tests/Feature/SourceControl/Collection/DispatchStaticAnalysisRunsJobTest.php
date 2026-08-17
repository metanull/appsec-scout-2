<?php

use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\StaticAnalysisRun;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sources\AzDo\AzDoClient;
use App\Sync\SystemIntegrationRuntime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function staticAnalysisDispatcherFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/AzDo/{$name}"));
}

function bindRealAzDoReposWithFakeClientForStaticAnalysis(array $responses): AzDoRepos
{
    $http = new Client(['handler' => new MockHandler($responses)]);
    $advSec = new Client(['handler' => new MockHandler([])]);

    $provider = new AzDoRepos(app(Vault::class));

    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('client');
    $property->setAccessible(true);
    $property->setValue($provider, new AzDoClient('testorg', 'pat', 'https://dev.azure.com', $http, $advSec));

    app()->instance(AzDoRepos::class, $provider);

    return $provider;
}

beforeEach(function () {
    app(Vault::class)->set('azdo-repos.pat', null, 'fake-pat');
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');

    // A repository this job might otherwise batch must never actually run
    // git/dotnet/roslynator/spotbugs in these tests — fake every process
    // invocation to succeed instantly. The cloned directory still needs to
    // exist on disk (empty is fine) so AnalyzeRepositoryJob's Finder-based
    // *.sln/*.class discovery doesn't throw for a directory that was never
    // really created.
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            File::ensureDirectoryExists(end($parts));
        }

        return Process::result(exitCode: 0);
    });
});

it('enumerates every non-disabled repository and completes the run as success', function () {
    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], staticAnalysisDispatcherFixture('projects.json')),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
        new Response(200, [], '{"value":[]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->source_control_id)->toBe('azdo-repos')
        ->and($run->status)->toBe('success')
        ->and($run->batch_id)->not->toBeNull()
        ->and($run->counts_json['repositories_considered'])->toBe(2)
        ->and($run->counts_json['repositories_completed'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(0);
});

it('skips a repository with no clone URL metadata and logs it, without failing the run', function () {
    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], '{"count":1,"value":[{"id":"repo-001","name":"backend-api","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001","project":{"id":"project-001","name":"SecurityProject"},"webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/backend-api"}]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_considered'])->toBe(0)
        ->and($run->batch_id)->toBeNull();

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('message', 'Repository has no clone URL, skipping.')->first();

    expect($errorLog)->not->toBeNull()
        ->and($errorLog->context_json['run'])->toBe($run->id);
});

it('marks the run as failure when the azdo-repos credential is not configured', function () {
    // Overwrite the credential seeded in beforeEach with an empty one so the
    // pre-flight hasRequiredSystemCredentials() check fails.
    app(Vault::class)->set('azdo-repos.pat', null, '');

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('failure')
        ->and($run->error_message)->not->toBeNull()
        ->and($run->batch_id)->toBeNull();
});

it('completes as partial when one of several repository jobs fails', function () {
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            if (str_contains(implode(' ', $parts), 'frontend-app')) {
                return Process::result(exitCode: 1, errorOutput: 'fatal: could not read from remote repository');
            }

            File::ensureDirectoryExists(end($parts));
        }

        return Process::result(exitCode: 0);
    });

    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('partial')
        ->and($run->counts_json['repositories_considered'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('is unique while a run is already in flight', function () {
    expect(new DispatchStaticAnalysisRunsJob)->toBeInstanceOf(ShouldBeUnique::class);
    expect((new DispatchStaticAnalysisRunsJob)->uniqueId())->toBe('static-analysis');
});

it('dispatches the batch onto the static-analysis queue, not repository-collection or default', function () {
    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
        new Response(200, [], '{"value":[]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $batchId = StaticAnalysisRun::query()->latest('id')->first()->batch_id;
    $batch = Bus::findBatch($batchId);

    expect($batch)->not->toBeNull();
    expect($batch->options['queue'] ?? null)->toBe('static-analysis');
});
