<?php

use App\Filament\Support\JobPayloadInspector;
use App\SourceControl\Collection\AnalyzeRepositoryJob;
use App\SourceControl\Collection\CollectRepositoryJob;
use App\SourceControl\Collection\RepositoryCollectionTarget;
use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use Illuminate\Support\Str;

function makeTarget(): RepositoryCollectionTarget
{
    return new RepositoryCollectionTarget(
        projectId: 'proj-guid',
        projectName: 'Apollo',
        projectDescription: null,
        projectUrl: null,
        repositoryId: 'repo-guid',
        repositoryName: 'Apollo.Api',
        repositoryBrowseUrl: null,
        repositoryCloneUrl: 'https://example/apollo.git',
        defaultBranch: null,
    );
}

function serializedCommandPayload(object $command, string $commandName): string
{
    return json_encode([
        'displayName' => $commandName,
        'data' => [
            'commandName' => $commandName,
            'command' => serialize($command),
        ],
    ], JSON_THROW_ON_ERROR);
}

it('reads the job name from displayName', function () {
    $payload = json_encode(['displayName' => 'App\\Jobs\\ExampleJob'], JSON_THROW_ON_ERROR);

    expect(JobPayloadInspector::jobName($payload))->toBe('App\\Jobs\\ExampleJob');
});

it('falls back to data.commandName when displayName is absent', function () {
    $payload = json_encode(['data' => ['commandName' => 'App\\Jobs\\FallbackJob']], JSON_THROW_ON_ERROR);

    expect(JobPayloadInspector::jobName($payload))->toBe('App\\Jobs\\FallbackJob');
});

it('reports Unknown job for a malformed payload', function () {
    expect(JobPayloadInspector::jobName('not json'))->toBe('Unknown job');
});

it('summarizes a truncated database column exception', function () {
    $exception = "PDOException: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'version_control_url' at row 1";

    expect(JobPayloadInspector::exceptionPreview($exception))
        ->toBe('Database value exceeded security_events.version_control_url. Run migrations, then retry or forget this failed job.');
});

it('truncates a long exception to 1000 characters plus the truncation suffix', function () {
    $exception = str_repeat('x', 2000);

    expect(JobPayloadInspector::exceptionPreview($exception))
        ->toBe(Str::limit($exception, 1000));
});

it('reads source id from a plain JSON payload shape', function () {
    $payload = json_encode(['data' => ['command' => ['sourceId' => 'azdo']]], JSON_THROW_ON_ERROR);

    expect(JobPayloadInspector::sourceOrTracker($payload))->toBe('source:azdo');
});

it('falls back to unserializing the command to find the source id of a FetchSourceJob', function () {
    $payload = serializedCommandPayload(new FetchSourceJob('azdo'), FetchSourceJob::class);

    expect(JobPayloadInspector::sourceOrTracker($payload))->toBe('source:azdo');
});

it('falls back to unserializing the command to find the tracker id of a RefreshWorkItemsJob', function () {
    $payload = serializedCommandPayload(new RefreshWorkItemsJob('jira-1'), RefreshWorkItemsJob::class);

    expect(JobPayloadInspector::sourceOrTracker($payload))->toBe('tracker:jira-1');
});

it('returns null for a payload with neither source nor tracker', function () {
    $payload = json_encode(['data' => ['command' => 'not-relevant']], JSON_THROW_ON_ERROR);

    expect(JobPayloadInspector::sourceOrTracker($payload))->toBeNull();
});

it('returns null for a malformed payload', function () {
    expect(JobPayloadInspector::sourceOrTracker('not json'))->toBeNull();
});

it('extracts the repository target from a serialized CollectRepositoryJob command', function () {
    $payload = serializedCommandPayload(
        new CollectRepositoryJob(makeTarget(), repositoryCollectionRunId: 42),
        CollectRepositoryJob::class,
    );

    expect(JobPayloadInspector::repositoryTarget($payload))->toBe([
        'project' => 'Apollo',
        'repository' => 'Apollo.Api',
    ]);
});

it('extracts the repository target from a serialized AnalyzeRepositoryJob command', function () {
    $payload = serializedCommandPayload(
        new AnalyzeRepositoryJob(makeTarget(), staticAnalysisRunId: 7),
        AnalyzeRepositoryJob::class,
    );

    expect(JobPayloadInspector::repositoryTarget($payload))->toBe([
        'project' => 'Apollo',
        'repository' => 'Apollo.Api',
    ]);
});

it('returns null for a job payload with no repository target', function () {
    $payload = serializedCommandPayload(new FetchSourceJob('azdo'), FetchSourceJob::class);

    expect(JobPayloadInspector::repositoryTarget($payload))->toBeNull();
});

it('returns null for a malformed payload when extracting the repository target', function () {
    expect(JobPayloadInspector::repositoryTarget('not json'))->toBeNull();
});
