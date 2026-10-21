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
use App\Models\ToolInvocation;
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
                    ? '{"bomFormat":"CycloneDX","components":[]}'
                    : '{"runs":[]}');
            }

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

beforeEach(function () {
    withoutDependencyTrackPush();
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
            File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    expect(Attachment::query()->count())->toBe(2)
        ->and(ErrorLog::query()->where('channel', 'repository-collection')->count())->toBe(1);
});

it('counts a repository as failed for the run when one Trivy scan fails but the clone and other two scans succeed', function () {
    fakeCollectorProcesses(function (array $parts, ?string $outputPath) {
        if (in_array('secret', $parts, true)) {
            return Process::result(exitCode: 1, errorOutput: 'trivy: scan failed');
        }

        if ($outputPath !== null) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    // Clone succeeded and the two non-failing reports were still attached —
    // not 0 (a clone failure) and not 3 (every scan succeeding).
    expect(Attachment::query()->count())->toBe(2);

    $errorLog = ErrorLog::query()->where('channel', 'repository-collection')->where('context_json->operation', AttachmentIngestionService::KIND_SECRETS)->first();
    expect($errorLog)->not->toBeNull();

    // A repository whose clone succeeded but whose Trivy scan logged a
    // failure must still count as failed for this run, per #484. With a
    // single repository considered, one failure out of one is the run's
    // 'failure' case (recordCompletion()'s own match block, unchanged by
    // #484) — 'partial' requires at least one repository to also succeed,
    // covered separately below.
    $run->refresh();
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_considered'])->toBe(1)
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('marks the run partial when one repository is fully clean and another has a failing Trivy scan', function () {
    // Toggled between the two handle() calls below: the workDir passed to
    // trivy is an anonymous scratch path (a fresh uuid per job), so which
    // repository is "currently running" is tracked by this flag rather than
    // by inspecting the command itself — mirrors
    // fakeStaticAnalysisPipelineProcessesAtCommit()'s own reasoning for why
    // per-repository behavior sometimes has to be sequenced instead of
    // parsed out of the faked command.
    $failSecrets = false;

    fakeCollectorProcesses(function (array $parts, ?string $outputPath) use (&$failSecrets) {
        if ($failSecrets && in_array('secret', $parts, true)) {
            return Process::result(exitCode: 1, errorOutput: 'trivy: scan failed');
        }

        if ($outputPath !== null) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest(considered: 2);

    // repo-001: every Trivy scan succeeds.
    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    // repo-002: clone succeeds, but the secrets scan fails.
    $failSecrets = true;

    (new CollectRepositoryJob(repositoryCollectionTarget(['repositoryId' => 'repo-002']), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $run->refresh();
    expect($run->status)->toBe('partial')
        ->and($run->counts_json['repositories_considered'])->toBe(2)
        ->and($run->counts_json['repositories_completed'])->toBe(2)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
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

// ---------------------------------------------------------------------------
// ToolInvocation rows (#489) — one per Trivy invocation per repository, on
// every outcome (findings, clean, skipped, or failed), not only on failure.
// ---------------------------------------------------------------------------

it('records a ToolInvocation row for a successful Trivy SBOM scan, with no credential value in command_json', function () {
    fakeCollectorProcesses();
    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $container = SecurityContainer::query()->where('source_container_id', 'repo-001')->firstOrFail();

    $invocation = ToolInvocation::query()->where('tool', 'trivy-sbom')->first();

    expect($invocation)->not->toBeNull()
        ->and($invocation->outcome)->toBe('ran_with_findings')
        ->and($invocation->run_type)->toBe(RepositoryCollectionRun::class)
        ->and($invocation->run_id)->toBe($run->id)
        ->and($invocation->security_container_id)->toBe($container->id);

    // The fake Trivy token seeded in beforeEach() ('fake-token') must never
    // appear anywhere in the stored command — only its redacted placeholder.
    expect($invocation->command_json)->toContain('***REDACTED***')
        ->and(json_encode($invocation->command_json))->not->toContain('fake-token');
});

it('records a ToolInvocation row with outcome failed and a non-null output_tail when a Trivy scan fails', function () {
    fakeCollectorProcesses(function (array $parts, ?string $outputPath) {
        if (in_array('secret', $parts, true)) {
            return Process::result(exitCode: 1, errorOutput: 'trivy: scan failed');
        }

        if ($outputPath !== null) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, str_contains(implode(' ', $parts), 'cyclonedx') ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $invocation = ToolInvocation::query()->where('tool', 'trivy-secret')->first();

    expect($invocation)->not->toBeNull()
        ->and($invocation->outcome)->toBe('failed')
        ->and($invocation->output_tail)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Maven Central mirror — Central rate-limits Trivy's burst of POM requests
// with a 30-minute 429 block, so every scan resolves `central` via a mirror.
// ---------------------------------------------------------------------------

it('points every Trivy scan at the configured Maven Central mirror', function () {
    config(['repository_collection.maven_mirror_url' => 'https://maven-mirror.example.test/maven2/?a=1&b=2']);
    $mirrors = [];

    Process::fake(function ($process) use (&$mirrors) {
        $parts = $process->command;

        if (($parts[0] ?? null) === 'trivy') {
            $mavenHome = $process->environment['MAVEN_HOME'] ?? null;
            $settings = $mavenHome !== null ? simplexml_load_file($mavenHome . '/conf/settings.xml') : false;
            $mirrors[] = $settings === false ? null : [(string) $settings->mirrors->mirror->mirrorOf, (string) $settings->mirrors->mirror->url];

            $outputPath = $parts[array_search('--output', $parts, true) + 1];
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, in_array('cyclonedx', $parts, true) ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    (new CollectRepositoryJob(repositoryCollectionTarget(), repositoryCollectionRunForJobTest()->id))
        ->handle(...collectRepositoryJobDependencies());

    expect($mirrors)->toBe(array_fill(0, 3, ['central', 'https://maven-mirror.example.test/maven2/?a=1&b=2']));
});

it('lets Trivy reach Maven Central directly when no mirror is configured', function () {
    config(['repository_collection.maven_mirror_url' => '']);
    $environments = [];

    Process::fake(function ($process) use (&$environments) {
        $parts = $process->command;

        if (($parts[0] ?? null) === 'trivy') {
            $environments[] = $process->environment;

            $outputPath = $parts[array_search('--output', $parts, true) + 1];
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, in_array('cyclonedx', $parts, true) ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');
        }

        return Process::result(exitCode: 0);
    });

    (new CollectRepositoryJob(repositoryCollectionTarget(), repositoryCollectionRunForJobTest()->id))
        ->handle(...collectRepositoryJobDependencies());

    expect($environments)->toBe([[], [], []]);
});

it('records Trivy\'s exit code and fatal error when a scan fails', function () {
    $stderr = "2026-10-21T15:51:20Z\tINFO\t[secret] Secret scanning is enabled\n"
        . "2026-10-21T15:51:22Z\tFATAL\tError\tremote Maven repository returned 429 Too Many Requests for https://repo.maven.apache.org/maven2/x.pom. Retry-After: 1800.\n"
        . "The repository blocks all subsequent requests from this IP until the block clears.\n";

    fakeCollectorProcesses(fn (array $parts) => Process::result(exitCode: 1, errorOutput: $stderr));

    (new CollectRepositoryJob(repositoryCollectionTarget(), repositoryCollectionRunForJobTest()->id))
        ->handle(...collectRepositoryJobDependencies());

    $errorLog = ErrorLog::query()->where('channel', 'repository-collection')->where('context_json->operation', AttachmentIngestionService::KIND_SECRETS)->firstOrFail();
    $invocation = ToolInvocation::query()->where('tool', 'trivy-secret')->firstOrFail();

    expect($errorLog->message)->toStartWith('Trivy scan did not produce output (exit code 1). FATAL')
        ->and($errorLog->message)->toContain('429 Too Many Requests')
        ->and($errorLog->message)->toContain('blocks all subsequent requests')
        ->and($errorLog->message)->not->toContain('Secret scanning is enabled')
        ->and($errorLog->context_json['exit_code'])->toBe(1)
        ->and($invocation->output_tail)->toContain('Secret scanning is enabled')
        ->and($invocation->output_tail)->toContain('429 Too Many Requests');
});

it('never stores the Trivy token from Trivy\'s own error output', function () {
    fakeCollectorProcesses(fn (array $parts) => Process::result(exitCode: 1, errorOutput: "FATAL\tauthentication failed with token fake-token\n"));

    (new CollectRepositoryJob(repositoryCollectionTarget(), repositoryCollectionRunForJobTest()->id))
        ->handle(...collectRepositoryJobDependencies());

    expect(ErrorLog::query()->where('message', 'like', '%fake-token%')->exists())->toBeFalse()
        ->and(ToolInvocation::query()->where('output_tail', 'like', '%fake-token%')->exists())->toBeFalse()
        ->and(ErrorLog::query()->where('message', 'like', '%***REDACTED***%')->count())->toBe(3);
});
