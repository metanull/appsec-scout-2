<?php

use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRepositoryState;
use App\Models\StaticAnalysisRun;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Collection\AnalyzeRepositoryJob;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use App\Sources\AzDo\AzDoClient;
use App\Sync\SystemIntegrationRuntime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\PendingBatch;
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

        if (($parts[0] ?? null) === 'opengrep') {
            $outputIndex = array_search('--output', $parts, true);
            $outputPath = $outputIndex !== false ? $parts[$outputIndex + 1] : null;

            if ($outputPath !== null) {
                File::ensureDirectoryExists(dirname($outputPath));
                File::put($outputPath, '{"runs":[]}');
            }
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

    // Same context key shape as repository-collection's identical failure
    // (project_id/project_name/repository_id/repository_name), not the
    // name-only shape this channel previously used.
    expect($errorLog)->not->toBeNull()
        ->and($errorLog->context_json['run'])->toBe($run->id)
        ->and($errorLog->context_json['project_id'])->toBe('project-001')
        ->and($errorLog->context_json['project_name'])->toBe('SecurityProject')
        ->and($errorLog->context_json['repository_id'])->toBe('repo-001')
        ->and($errorLog->context_json['repository_name'])->toBe('backend-api');
});

it('excludes a repository with no clone URL from repositories_considered and records it by reason', function () {
    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], '{"count":2,"value":['
            . '{"id":"repo-001","name":"backend-api","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001","project":{"id":"project-001","name":"SecurityProject"},"defaultBranch":"refs/heads/main","remoteUrl":"https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api","webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/backend-api"},'
            . '{"id":"repo-002","name":"frontend-app","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-002","project":{"id":"project-001","name":"SecurityProject"},"webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/frontend-app"}'
            . ']}'),
    ]);

    // The batch dispatch below runs the single surviving target's
    // AnalyzeRepositoryJob synchronously (the `sync` queue connection), so
    // by the time $run is re-fetched, recordCompletion() has already
    // rewritten counts_json once — proving the excluded keys survive that
    // rewrite rather than only asserting their dispatch-time value.
    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_considered'])->toBe(1)
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_excluded_pre_dispatch'])->toBe(1)
        ->and($run->counts_json['repositories_excluded_by_reason'])->toBe(['no_clone_url' => 1]);
});

it('records repositories_skipped_by_reason when a repository is skipped as unchanged', function () {
    $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4';

    $system = SoftwareSystem::factory()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'project-001',
    ]);

    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
    ]);

    StaticAnalysisRepositoryState::query()->create([
        'security_container_id' => $container->id,
        'commit_sha' => $sha,
        'analyzed_at' => now()->subDay(),
        'analyzed_run_id' => null,
    ]);

    Process::fake(function ($process) use ($sha) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: "{$sha}\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            File::ensureDirectoryExists(end($parts));
        }

        return Process::result(exitCode: 0);
    });

    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], '{"count":1,"value":[{"id":"repo-001","name":"backend-api","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001","project":{"id":"project-001","name":"SecurityProject"},"defaultBranch":"refs/heads/main","remoteUrl":"https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api","webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/backend-api"}]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_considered'])->toBe(1)
        ->and($run->counts_json['repositories_skipped'])->toBe(1)
        ->and($run->counts_json['repositories_skipped_by_reason'])->toBe(['unchanged_commit' => 1]);
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

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('message', $run->error_message)->latest('id')->first();

    expect($errorLog)->not->toBeNull()
        ->and($errorLog->level)->toBe('error')
        ->and($errorLog->context_json['run'])->toBe($run->id)
        ->and($errorLog->context_json['operation'])->toBe('discover');
});

it('logs and marks the run as failure when the batch dispatch itself throws', function () {
    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
    ]);

    Bus::shouldReceive('batch')->once()->andThrow(new RuntimeException('Batch dispatch failed.'));

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->status)->toBe('failure')
        ->and($run->error_message)->toBe('Batch dispatch failed.')
        ->and($run->batch_id)->toBeNull();

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('message', 'Batch dispatch failed.')->latest('id')->first();

    expect($errorLog)->not->toBeNull()
        ->and($errorLog->level)->toBe('error')
        ->and($errorLog->context_json['run'])->toBe($run->id)
        ->and($errorLog->context_json['operation'])->toBe('dispatch')
        ->and($errorLog->trace)->not->toBeNull();
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

        if (($parts[0] ?? null) === 'opengrep') {
            $outputIndex = array_search('--output', $parts, true);
            $outputPath = $outputIndex !== false ? $parts[$outputIndex + 1] : null;

            if ($outputPath !== null) {
                File::ensureDirectoryExists(dirname($outputPath));
                File::put($outputPath, '{"runs":[]}');
            }
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

it('initialises counts_json with a zero repositories_skipped counter', function () {
    Bus::fake();

    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], staticAnalysisDispatcherFixture('projects.json')),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
        new Response(200, [], '{"value":[]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = StaticAnalysisRun::query()->latest('id')->first();

    expect($run->counts_json['repositories_skipped'])->toBe(0);
});

it('passes the force flag through to every batched AnalyzeRepositoryJob', function () {
    Bus::fake();

    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], staticAnalysisDispatcherFixture('projects.json')),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
        new Response(200, [], '{"value":[]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob(force: true))->handle(app(SystemIntegrationRuntime::class));

    Bus::assertBatched(function (PendingBatch $batch): bool {
        return $batch->jobs->isNotEmpty()
            && $batch->jobs->every(fn (AnalyzeRepositoryJob $job): bool => $job->force === true);
    });
});

it('leaves force off for an ordinary sweep', function () {
    Bus::fake();

    bindRealAzDoReposWithFakeClientForStaticAnalysis([
        new Response(200, [], staticAnalysisDispatcherFixture('projects.json')),
        new Response(200, [], staticAnalysisDispatcherFixture('repositories.json')),
        new Response(200, [], '{"value":[]}'),
    ]);

    (new DispatchStaticAnalysisRunsJob)->handle(app(SystemIntegrationRuntime::class));

    Bus::assertBatched(function (PendingBatch $batch): bool {
        return $batch->jobs->isNotEmpty()
            && $batch->jobs->every(fn (AnalyzeRepositoryJob $job): bool => $job->force === false);
    });
});
