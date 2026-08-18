<?php

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SourceControl\Collection\CollectRepositoryJob;
use App\SourceControl\Collection\RepositoryCollectionTarget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function repositoryCollectionRunForJobTest(int $considered = 1): RepositoryCollectionRun
{
    return RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now(),
        'status' => 'running',
        'counts_json' => [
            'repositories_considered' => $considered,
            'repositories_completed' => 0,
            'repositories_failed' => 0,
        ],
    ]);
}

function repositoryCollectionTarget(array $overrides = []): RepositoryCollectionTarget
{
    return new RepositoryCollectionTarget(
        projectId: $overrides['projectId'] ?? 'project-001',
        projectName: $overrides['projectName'] ?? 'SecurityProject',
        projectDescription: $overrides['projectDescription'] ?? 'A project',
        projectUrl: $overrides['projectUrl'] ?? 'https://dev.azure.com/testorg/SecurityProject',
        repositoryId: $overrides['repositoryId'] ?? 'repo-001',
        repositoryName: $overrides['repositoryName'] ?? 'backend-api',
        repositoryBrowseUrl: $overrides['repositoryBrowseUrl'] ?? 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
        repositoryCloneUrl: $overrides['repositoryCloneUrl'] ?? 'https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api',
        defaultBranch: $overrides['defaultBranch'] ?? 'main',
    );
}

function seedAzdoReposCredential(): void
{
    app(Vault::class)->set('azdo-repos.pat', null, 'fake-pat');
}

function collectRepositoryJobDependencies(): array
{
    return [app(AttachmentTargetResolver::class), app(AttachmentService::class), app(Vault::class)];
}

function fakeCollectorProcesses(?Closure $onTrivy = null): void
{
    Process::fake(function ($process) use ($onTrivy) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git') {
            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'trivy') {
            $outputIndex = array_search('--output', $parts, true);
            $outputPath = $outputIndex !== false ? $parts[$outputIndex + 1] : null;

            if ($onTrivy !== null) {
                return $onTrivy($parts, $outputPath);
            }

            if ($outputPath !== null) {
                File::ensureDirectoryExists(dirname($outputPath));
                File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx')
                    ? '{"components":[]}'
                    : '{"runs":[]}');
            }

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

beforeEach(function () {
    config(['repository_collection.trivy_server_url' => 'http://trivy-server:4954']);
    config(['repository_collection.trivy_token_file' => storage_path('app/private/test-trivy-token')]);
    File::ensureDirectoryExists(storage_path('app/private'));
    File::put(storage_path('app/private/test-trivy-token'), 'fake-token');

    seedAzdoReposCredential();
});

afterEach(function () {
    File::delete(storage_path('app/private/test-trivy-token'));
});

it('clones a repository, runs three Trivy scans, and attaches every report', function () {
    fakeCollectorProcesses();
    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    expect($system)->not->toBeNull();

    $container = SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->first();
    expect($container)->not->toBeNull();

    $attachments = Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->get();

    expect($attachments)->toHaveCount(3)
        ->and($attachments->pluck('kind')->sort()->values()->all())->toBe([
            AttachmentIngestionService::KIND_SBOM,
            AttachmentIngestionService::KIND_SECRETS,
            AttachmentIngestionService::KIND_VULNERABILITIES,
        ]);

    // The only repository considered by this run just completed successfully.
    $run->refresh();
    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(0);
});

it('attaches results to the same rows a live AzDO sync already created, not a duplicate', function () {
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'project-001',
        'name' => 'Live-synced name',
    ]);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
        'name' => 'Live-synced container',
    ]);

    fakeCollectorProcesses();
    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->count())->toBe(1)
        ->and(SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->count())->toBe(1);

    $container->refresh();
    expect($container->name)->toBe('Live-synced container');
    expect(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->count())->toBe(3);
});

it('logs and records completion as failure when every repository fails, without throwing', function () {
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: repository not found');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();
    $job = new CollectRepositoryJob(repositoryCollectionTarget(), $run->id);

    // A clone failure is a logged, per-repository outcome — like a Trivy
    // scan failure — not a job-level exception: it must never trigger
    // Laravel's retry mechanism, since "repository not found" will not
    // succeed on attempt 2 or 3.
    $job->handle(...collectRepositoryJobDependencies());

    expect(Attachment::query()->count())->toBe(0);

    $errorLog = ErrorLog::query()->where('channel', 'repository-collection')->where('message', 'like', 'git clone failed%')->first();

    expect($errorLog)->not->toBeNull()
        ->and($errorLog->context_json['run'])->toBe($run->id);

    $run->refresh();
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('records completion as partial when some but not all repositories fail', function () {
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: repository not found');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest(considered: 2);

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $run->refresh();
    expect($run->status)->toBe('running')
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(1);

    fakeCollectorProcesses();

    (new CollectRepositoryJob(repositoryCollectionTarget(['repositoryId' => 'repo-002']), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $run->refresh();
    expect($run->status)->toBe('partial')
        ->and($run->counts_json['repositories_completed'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('records the owning system/container on a logged clone failure, and only one row per failure', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
    ]);

    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: repository not found');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $errorLogs = ErrorLog::query()->where('channel', 'repository-collection')->where('message', 'like', 'git clone failed%')->get();

    expect($errorLogs)->toHaveCount(1);
    expect($errorLogs->first()->software_system_id)->toBe($system->id)
        ->and($errorLogs->first()->security_container_id)->toBe($container->id);
});

it('records no owner on a logged failure when the system/container was never resolved', function () {
    Process::fake(function ($process) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: repository not found');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $errorLog = ErrorLog::query()->where('channel', 'repository-collection')->where('message', 'like', 'git clone failed%')->firstOrFail();

    expect($errorLog->software_system_id)->toBeNull()
        ->and($errorLog->security_container_id)->toBeNull();
});

it('does not let one failing Trivy scan prevent the other two from attaching', function () {
    fakeCollectorProcesses(function (array $parts, ?string $outputPath) {
        if (in_array('secret', $parts, true)) {
            return Process::result(exitCode: 1, errorOutput: 'trivy: scan failed');
        }

        if ($outputPath !== null) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    expect(Attachment::query()->count())->toBe(2)
        ->and(ErrorLog::query()->where('channel', 'repository-collection')->count())->toBe(1);
});

it('records the owning system/container and a single row when the job-level failed() hook fires', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
    ]);

    $run = repositoryCollectionRunForJobTest();
    $job = new CollectRepositoryJob(repositoryCollectionTarget(), $run->id);

    $job->failed(new RuntimeException('Every retry attempt exhausted.'));

    $errorLogs = ErrorLog::query()->where('channel', 'repository-collection')->where('message', 'Every retry attempt exhausted.')->get();

    expect($errorLogs)->toHaveCount(1);
    expect($errorLogs->first()->software_system_id)->toBe($system->id)
        ->and($errorLogs->first()->security_container_id)->toBe($container->id)
        ->and($errorLogs->first()->trace)->not->toBeNull();
});
