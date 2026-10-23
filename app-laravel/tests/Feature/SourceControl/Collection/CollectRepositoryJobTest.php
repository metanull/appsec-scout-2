<?php

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\Enums\EventState;
use App\Models\ErrorLog;
use App\Models\LocalFinding;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\ToolInvocation;
use App\SourceControl\Collection\CollectRepositoryJob;
use App\SourceControl\Collection\Hygiene\RepositoryHygieneCheck;
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
    return [app(AttachmentTargetResolver::class), app(AttachmentService::class), app(Vault::class), app(RepositoryHygieneCheck::class)];
}

/**
 * Stands in for `git clone`: creates the clone's working tree (the last argument) holding the
 * given files, since the job inspects the tree for committed archives. A no-op for any other
 * command.
 *
 * @param  list<string>  $files
 */
function createClonedTree(array $parts, array $files = []): void
{
    if (($parts[0] ?? null) !== 'git' || ($parts[1] ?? null) !== 'clone') {
        return;
    }

    $workDir = (string) end($parts);
    File::ensureDirectoryExists($workDir);

    foreach ($files as $file) {
        File::ensureDirectoryExists(dirname($workDir . '/' . $file));
        File::put($workDir . '/' . $file, 'PK');
    }
}

function fakeCollectorProcesses(?Closure $onTrivy = null): void
{
    Process::fake(function ($process) use ($onTrivy) {
        $command = $process->command;
        $parts = is_array($command) ? $command : preg_split('/\s+/', (string) $command);

        if (($parts[0] ?? null) === 'git') {
            createClonedTree($parts);

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
        createClonedTree($parts);

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
        createClonedTree($parts);

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

// ---------------------------------------------------------------------------
// Committed JAR/WAR/EAR archives — `trivy fs` reads only manifests and lock
// files, so archives get two extra `trivy rootfs` scans with report kinds of
// their own.
// ---------------------------------------------------------------------------

/**
 * Fakes a clone holding $files and a Trivy that answers each scan, keyed "<target>:<sbom|vuln|
 * secret>", with the given payload (an empty report by default, a failure for false), recording
 * every Trivy command into $commands.
 *
 * @param  list<string>  $files
 * @param  array<string, string|false>  $reports
 */
function fakeClonedTreeScans(array $files, array $reports = [], ?array &$commands = null): void
{
    $commands = [];

    Process::fake(function ($process) use ($files, $reports, &$commands) {
        $parts = $process->command;
        createClonedTree($parts, $files);

        if (($parts[0] ?? null) !== 'trivy') {
            return Process::result(exitCode: 0);
        }

        $commands[] = $parts;
        $option = fn (string $name): ?string => ($index = array_search($name, $parts, true)) === false ? null : $parts[$index + 1];
        $isSbom = $option('--format') === 'cyclonedx';
        $payload = $reports[$parts[1] . ':' . ($isSbom ? 'sbom' : $option('--scanners'))] ?? ($isSbom ? '{"bomFormat":"CycloneDX","components":[]}' : '{"runs":[]}');

        if ($payload === false) {
            return Process::result(exitCode: 1, errorOutput: "FATAL\tjava DB download failed");
        }

        File::put((string) $option('--output'), $payload);

        return Process::result(exitCode: 0);
    });
}

/** @param  list<string>  $purls */
function archiveScanCycloneDx(array $purls): string
{
    return json_encode([
        'bomFormat' => 'CycloneDX',
        'specVersion' => '1.6',
        'components' => array_map(fn (string $purl, int $index): array => [
            'bom-ref' => "component-{$index}",
            'type' => 'library',
            'name' => "component-{$index}",
            'version' => '1.0.0',
            'purl' => $purl,
        ], $purls, array_keys($purls)),
    ], JSON_THROW_ON_ERROR);
}

/** @param  array<string, string>  $findings  rule id => file path */
function archiveScanSarif(array $findings): string
{
    return json_encode(['version' => '2.1.0', 'runs' => [[
        'tool' => ['driver' => ['name' => 'Trivy', 'rules' => array_map(fn (string $id): array => ['id' => $id, 'shortDescription' => ['text' => "{$id} description"]], array_keys($findings))]],
        'results' => array_map(fn (string $id, string $path): array => [
            'ruleId' => $id,
            'level' => 'error',
            'message' => ['text' => "Package: org.example:{$id}\nInstalled Version: 1.0.0\nSeverity: CRITICAL"],
            'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $path], 'region' => ['startLine' => 1]]]],
        ], array_keys($findings), $findings),
    ]]], JSON_THROW_ON_ERROR);
}

function collectRepositoryOnce(): void
{
    (new CollectRepositoryJob(repositoryCollectionTarget(), repositoryCollectionRunForJobTest()->id))
        ->handle(...collectRepositoryJobDependencies());
}

it('scans a repository without archives exactly as before: three trivy fs scans and no Java DB options', function () {
    config(['repository_collection.trivy_cache_dir' => '/var/cache/trivy', 'repository_collection.trivy_java_db_repository' => 'registry.example.test/trivy-java-db:1']);
    fakeClonedTreeScans(['pom.xml', 'src/main/java/App.java', '.git/objects/pack/old.jar'], commands: $commands);

    collectRepositoryOnce();

    expect(array_map(fn (array $command): string => $command[1], $commands))->toBe(['fs', 'fs', 'fs'])
        ->and(json_encode($commands))->not->toContain('--cache-dir')
        ->and(json_encode($commands))->not->toContain('--java-db-repository')
        ->and(Attachment::query()->pluck('kind')->sort()->values()->all())->toBe(['sbom', 'secrets', 'vulnerabilities']);
});

it('also scans committed archives with trivy rootfs, storing an SBOM and vulnerabilities of their own kinds', function () {
    config(['repository_collection.trivy_cache_dir' => '/var/cache/trivy', 'repository_collection.trivy_java_db_repository' => 'registry.example.test/trivy-java-db:1']);
    fakeClonedTreeScans(['server/default/lib/struts2-core-2.3.16.3.jar'], commands: $commands);

    collectRepositoryOnce();

    [$sbom, $vulnerabilities] = array_slice($commands, 3);

    expect(array_map(fn (array $command): string => $command[1], $commands))->toBe(['fs', 'fs', 'fs', 'rootfs', 'rootfs'])
        ->and($sbom)->toContain('cyclonedx')
        ->and($sbom)->not->toContain('--scanners')
        ->and(array_slice($vulnerabilities, array_search('--scanners', $vulnerabilities, true), 6))
        ->toBe(['--scanners', 'vuln', '--cache-dir', '/var/cache/trivy', '--java-db-repository', 'registry.example.test/trivy-java-db:1'])
        ->and($vulnerabilities)->toContain('sarif')
        ->and(Attachment::query()->pluck('kind')->sort()->values()->all())
        ->toBe(['sbom', 'sbom-archives', 'secrets', 'vulnerabilities', 'vulnerabilities-archives'])
        ->and(ToolInvocation::query()->pluck('tool')->all())
        ->toContain('trivy-sbom-archives', 'trivy-vuln-archives');
});

it('detects archives in any letter case', function (string $file) {
    fakeClonedTreeScans([$file], commands: $commands);

    collectRepositoryOnce();

    expect($commands)->toHaveCount(5);
})->with(['deploy/phoenix.EAR', 'webapps/app.War', 'lib/commons-collections-3.2.jar']);

it('leaves Trivy its own cache directory and Java DB repository when none is configured', function () {
    config(['repository_collection.trivy_cache_dir' => '', 'repository_collection.trivy_java_db_repository' => '']);
    fakeClonedTreeScans(['lib/app.jar'], commands: $commands);

    collectRepositoryOnce();

    expect(json_encode($commands))->not->toContain('--cache-dir')
        ->and(json_encode($commands))->not->toContain('--java-db-repository');
});

it('keeps the archive and lock-file results apart, so neither scan resolves the other\'s', function () {
    $container = SecurityContainer::factory()->create([
        'software_system_id' => SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001'])->id,
        'source_container_id' => 'repo-001',
    ]);

    fakeClonedTreeScans(['lib/struts2-core.jar'], [
        'fs:sbom' => archiveScanCycloneDx(['pkg:maven/org.example/app@1.0.0']),
        'fs:vuln' => archiveScanSarif(['CVE-2024-0001' => 'pom.xml']),
        'rootfs:sbom' => archiveScanCycloneDx(['pkg:maven/org.apache.struts/struts2-core@2.3.16.3']),
        'rootfs:vuln' => archiveScanSarif(['CVE-2017-5638' => 'lib/struts2-core.jar']),
    ]);

    collectRepositoryOnce();

    $finding = fn (string $rule): LocalFinding => LocalFinding::query()->where('owner_id', $container->id)->where('rule_id', $rule)->sole();
    $component = fn (string $purl): SoftwareComponent => SoftwareComponent::query()->where('owner_id', $container->id)->where('purl', $purl)->sole();

    expect($finding('CVE-2024-0001')->report_kind)->toBe('vulnerabilities')
        ->and($finding('CVE-2017-5638')->report_kind)->toBe('vulnerabilities-archives')
        ->and($finding('CVE-2017-5638')->kind)->toBe(LocalFinding::KIND_VULNERABILITY)
        ->and($finding('CVE-2024-0001')->status)->toBe(EventState::Open)
        ->and($finding('CVE-2017-5638')->status)->toBe(EventState::Open)
        ->and($component('pkg:maven/org.example/app@1.0.0')->report_kind)->toBe('sbom')
        ->and($component('pkg:maven/org.apache.struts/struts2-core@2.3.16.3')->report_kind)->toBe('sbom-archives')
        ->and(SoftwareComponent::query()->where('owner_id', $container->id)->whereNotNull('removed_at')->exists())->toBeFalse();
});

it('rescans a repository whose archives were deleted, resolving only the archive results', function () {
    $container = SecurityContainer::factory()->create([
        'software_system_id' => SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001'])->id,
        'source_container_id' => 'repo-001',
    ]);
    $lockFileReports = [
        'fs:sbom' => archiveScanCycloneDx(['pkg:maven/org.example/app@1.0.0']),
        'fs:vuln' => archiveScanSarif(['CVE-2024-0001' => 'pom.xml']),
    ];

    fakeClonedTreeScans(['lib/struts2-core.jar'], [
        ...$lockFileReports,
        'rootfs:sbom' => archiveScanCycloneDx(['pkg:maven/org.apache.struts/struts2-core@2.3.16.3']),
        'rootfs:vuln' => archiveScanSarif(['CVE-2017-5638' => 'lib/struts2-core.jar']),
    ]);
    collectRepositoryOnce();

    fakeClonedTreeScans(['pom.xml'], $lockFileReports, $commands);
    collectRepositoryOnce();

    $status = fn (string $rule): EventState => LocalFinding::query()->where('owner_id', $container->id)->where('rule_id', $rule)->sole()->status;
    $removed = fn (string $purl): bool => SoftwareComponent::query()->where('owner_id', $container->id)->where('purl', $purl)->sole()->removed_at !== null;

    expect(array_map(fn (array $command): string => $command[1], $commands))->toBe(['fs', 'fs', 'fs', 'rootfs', 'rootfs'])
        ->and($status('CVE-2024-0001'))->toBe(EventState::Open)
        ->and($status('CVE-2017-5638'))->toBe(EventState::Resolved)
        ->and($removed('pkg:maven/org.example/app@1.0.0'))->toBeFalse()
        ->and($removed('pkg:maven/org.apache.struts/struts2-core@2.3.16.3'))->toBeTrue();
});

it('logs a failed archive scan and counts the repository failed, keeping every other report', function () {
    fakeClonedTreeScans(['lib/app.jar'], ['rootfs:vuln' => false]);
    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $errorLog = ErrorLog::query()->where('channel', 'repository-collection')->sole();

    expect($errorLog->context_json['operation'])->toBe('vulnerabilities-archives')
        ->and($errorLog->message)->toContain('java DB download failed')
        ->and(Attachment::query()->pluck('kind')->sort()->values()->all())->toBe(['sbom', 'sbom-archives', 'secrets', 'vulnerabilities'])
        ->and(ToolInvocation::query()->where('tool', 'trivy-vuln-archives')->sole()->outcome)->toBe('failed')
        ->and($run->refresh()->status)->toBe('failure');
});

// ---------------------------------------------------------------------------
// Repository hygiene — build output and binaries committed to Git, read from
// `git ls-files`, stored as a `repo-hygiene` report of its own.
// ---------------------------------------------------------------------------

/**
 * Fakes a clone whose `git ls-files` lists the given blobs (path => size; false makes it fail),
 * and a Trivy whose secret scan reports the given paths.
 *
 * @param  array<string, int>|false  $tracked
 * @param  list<string>  $secretPaths
 */
function fakeHygieneCollection(array|false $tracked, array $secretPaths = []): void
{
    Process::fake(function ($process) use ($tracked, $secretPaths) {
        $parts = $process->command;
        createClonedTree($parts);

        if (in_array('ls-files', $parts, true)) {
            return $tracked === false
                ? Process::result(exitCode: 128, errorOutput: 'fatal: not a git repository')
                : Process::result(output: lsFilesOutput($tracked));
        }

        if (($parts[0] ?? null) === 'trivy') {
            $option = fn (string $name): ?string => ($index = array_search($name, $parts, true)) === false ? null : $parts[$index + 1];
            File::put((string) $option('--output'), match (true) {
                $option('--format') === 'cyclonedx' => '{"bomFormat":"CycloneDX","components":[]}',
                $option('--scanners') === 'secret' => trivySecretsSarif($secretPaths),
                default => '{"runs":[]}',
            });
        }

        return Process::result(exitCode: 0);
    });
}

function hygieneFinding(string $folder): LocalFinding
{
    return LocalFinding::query()->where('report_kind', AttachmentIngestionService::KIND_REPO_HYGIENE)->where('file_path', $folder)->sole();
}

it('stores committed build output as one repo-hygiene finding per folder, HIGH where it holds a secret', function () {
    fakeHygieneCollection([
        'Office365/bin/Release/appsettings.json' => 400,
        'Office365/bin/Release/Office365.dll' => 60000,
        'Office365/obj/Release/appsettings.json' => 400,
        'Office365/appsettings.json' => 400,
        'lib/legacy.jar' => 1000,
    ], ['Office365/bin/Release/appsettings.json', 'Office365/appsettings.json']);

    collectRepositoryOnce();

    $container = SecurityContainer::query()->where('source_container_id', 'repo-001')->sole();
    $bin = hygieneFinding('Office365/bin');

    expect(Attachment::query()->where('kind', AttachmentIngestionService::KIND_REPO_HYGIENE)->sole()->owner_id)->toBe($container->id)
        ->and(LocalFinding::query()->where('report_kind', AttachmentIngestionService::KIND_REPO_HYGIENE)->pluck('file_path')->sort()->values()->all())
        ->toBe(['Office365/bin', 'Office365/obj', 'lib'])
        ->and($bin->kind)->toBe(LocalFinding::KIND_CODE_QUALITY)
        ->and($bin->rule_id)->toBe('repo-hygiene/committed-build-output')
        ->and($bin->severity)->toBe('HIGH')
        ->and($bin->metadata['result']['properties'])->toMatchArray(['fileCount' => 2, 'sizeBytes' => 60400, 'secretCount' => 1])
        ->and($bin->helpMarkdown())->toContain('CICD-SEC-9')
        ->and(hygieneFinding('Office365/obj')->severity)->toBe('MEDIUM')
        ->and(hygieneFinding('lib')->rule_id)->toBe('repo-hygiene/committed-binary')
        ->and(ToolInvocation::query()->where('tool', 'repo-hygiene')->sole()->outcome)->toBe('ran_with_findings');
});

it('stores no hygiene report for a repository that never committed build output', function () {
    fakeHygieneCollection(['src/App.cs' => 10, 'README.md' => 10]);
    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    expect(Attachment::query()->pluck('kind')->sort()->values()->all())->toBe(['sbom', 'secrets', 'vulnerabilities'])
        ->and(ToolInvocation::query()->where('tool', 'repo-hygiene')->sole()->outcome)->toBe('ran_clean')
        ->and($run->refresh()->status)->toBe('success');
});

it('resolves the finding of a folder removed from Git on the next collection', function () {
    fakeHygieneCollection(['web/dist/app.js' => 10, 'web/node_modules/a/index.js' => 10]);
    collectRepositoryOnce();

    fakeHygieneCollection(['web/node_modules/a/index.js' => 10]);
    collectRepositoryOnce();

    expect(hygieneFinding('web/dist')->status)->toBe(EventState::Resolved)
        ->and(hygieneFinding('web/node_modules')->status)->toBe(EventState::Open);

    fakeHygieneCollection(['src/index.ts' => 10]);
    collectRepositoryOnce();

    expect(hygieneFinding('web/node_modules')->status)->toBe(EventState::Resolved)
        ->and(Attachment::query()->where('kind', AttachmentIngestionService::KIND_REPO_HYGIENE)->count())->toBe(3);
});

it('raises a finding to HIGH once its folder holds a secret, and keeps it as one row', function () {
    fakeHygieneCollection(['Api/publish/appsettings.json' => 10]);
    collectRepositoryOnce();

    expect(hygieneFinding('Api/publish')->severity)->toBe('MEDIUM');

    fakeHygieneCollection(['Api/publish/appsettings.json' => 10], ['Api/publish/appsettings.json']);
    collectRepositoryOnce();

    expect(hygieneFinding('Api/publish')->severity)->toBe('HIGH');
});

it('logs a failed hygiene check and counts the repository failed, keeping every Trivy report', function () {
    fakeHygieneCollection(false);
    $run = repositoryCollectionRunForJobTest();

    (new CollectRepositoryJob(repositoryCollectionTarget(), $run->id))
        ->handle(...collectRepositoryJobDependencies());

    $errorLog = ErrorLog::query()->where('channel', 'repository-collection')->sole();

    expect($errorLog->context_json['operation'])->toBe('repo-hygiene')
        ->and($errorLog->message)->toContain('git ls-files failed', 'not a git repository')
        ->and(Attachment::query()->pluck('kind')->sort()->values()->all())->toBe(['sbom', 'secrets', 'vulnerabilities'])
        ->and(ToolInvocation::query()->where('tool', 'repo-hygiene')->sole()->outcome)->toBe('failed')
        ->and($run->refresh()->status)->toBe('failure');
});
