<?php

use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use App\Sources\AzDo\AzDoClient;
use App\Sync\SystemIntegrationRuntime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function dispatcherFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/AzDo/{$name}"));
}

function bindRealAzDoReposWithFakeClient(array $responses): AzDoRepos
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
    // git/trivy in these tests — fake every process invocation to succeed
    // instantly with an empty report, matching CollectRepositoryJobTest's
    // own fakeCollectorProcesses() shape.
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'trivy') {
            $outputIndex = array_search('--output', $parts, true);
            $outputPath = $outputIndex !== false ? $parts[$outputIndex + 1] : null;

            if ($outputPath !== null) {
                File::ensureDirectoryExists(dirname($outputPath));
                File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"components":[]}' : '{"runs":[]}');
            }
        }

        return Process::result(exitCode: 0);
    });

    config(['repository_collection.trivy_server_url' => 'http://trivy-server:4954']);
    config(['repository_collection.trivy_token_file' => storage_path('app/private/dispatcher-test-token')]);
    File::ensureDirectoryExists(storage_path('app/private'));
    File::put(storage_path('app/private/dispatcher-test-token'), 'fake-token');
});

afterEach(function () {
    File::delete(storage_path('app/private/dispatcher-test-token'));
});

it('enumerates every non-disabled repository and completes the run as success', function () {
    bindRealAzDoReposWithFakeClient([
        new Response(200, [], dispatcherFixture('projects.json')),
        new Response(200, [], dispatcherFixture('repositories.json')),
        new Response(200, [], '{"value":[]}'),
    ]);

    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = RepositoryCollectionRun::query()->latest('id')->first();

    // Temporary diagnostics: confirm whether the batched jobs actually ran
    // under QUEUE_CONNECTION=sync and, if so, why finally() hasn't updated
    // the run yet.
    dump([
        'run_status' => $run?->status,
        'job_batches' => DB::table('job_batches')->get()->toArray(),
        'attachments' => Attachment::query()->count(),
        'queue_default_connection' => config('queue.default'),
    ]);

    expect($run)->not->toBeNull()
        ->and($run->source_control_id)->toBe('azdo-repos')
        ->and($run->status)->toBe('success')
        ->and($run->batch_id)->not->toBeNull()
        ->and($run->counts_json['repositories_considered'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(0);
});

it('skips a repository with no clone URL metadata and logs it, without failing the run', function () {
    bindRealAzDoReposWithFakeClient([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], '{"count":1,"value":[{"id":"repo-001","name":"backend-api","url":"https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001","project":{"id":"project-001","name":"SecurityProject"},"webUrl":"https://dev.azure.com/testorg/SecurityProject/_git/backend-api"}]}'),
    ]);

    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = RepositoryCollectionRun::query()->latest('id')->first();

    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_considered'])->toBe(0)
        ->and($run->batch_id)->toBeNull()
        ->and(ErrorLog::query()->where('channel', 'repository-collection')->where('message', 'Repository has no clone URL, skipping.')->exists())->toBeTrue();
});

it('marks the run as failure when the azdo-repos credential is not configured', function () {
    // Overwrite the credential seeded in beforeEach with an empty one so the
    // pre-flight hasRequiredSystemCredentials() check fails.
    app(Vault::class)->set('azdo-repos.pat', null, '');

    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = RepositoryCollectionRun::query()->latest('id')->first();

    expect($run->status)->toBe('failure')
        ->and($run->error_message)->not->toBeNull()
        ->and($run->batch_id)->toBeNull();
});

it('completes as success with a non-zero repositories_failed count when one repository job fails', function () {
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone' && str_contains(implode(' ', $parts), 'frontend-app')) {
            return Process::result(exitCode: 1, errorOutput: 'fatal: could not read from remote repository');
        }

        if (($parts[0] ?? null) === 'trivy') {
            $outputIndex = array_search('--output', $parts, true);
            $outputPath = $outputIndex !== false ? $parts[$outputIndex + 1] : null;

            if ($outputPath !== null) {
                File::ensureDirectoryExists(dirname($outputPath));
                File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"components":[]}' : '{"runs":[]}');
            }
        }

        return Process::result(exitCode: 0);
    });

    bindRealAzDoReposWithFakeClient([
        new Response(200, [], '{"count":1,"value":[{"id":"project-001","name":"SecurityProject","url":"https://dev.azure.com/testorg/_apis/projects/project-001"}]}'),
        new Response(200, [], dispatcherFixture('repositories.json')),
    ]);

    (new DispatchRepositoryCollectionRunsJob)->handle(app(SystemIntegrationRuntime::class));

    $run = RepositoryCollectionRun::query()->latest('id')->first();

    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_considered'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('is unique while a run is already in flight', function () {
    expect(new DispatchRepositoryCollectionRunsJob)->toBeInstanceOf(ShouldBeUnique::class);
    expect((new DispatchRepositoryCollectionRunsJob)->uniqueId())->toBe('repository-collection');
});
