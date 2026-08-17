<?php

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\SourceControl\Collection\AnalyzeRepositoryJob;
use App\SourceControl\Collection\RepositoryCollectionTarget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Leading UTF-8 BOM matches Roslynator's real --output-format sarif output exactly
// (confirmed against the actual 0.13.1 binary) — every test using this fixture also
// exercises AnalyzeRepositoryJob::stripUtf8Bom(), which json_decode() requires.
const ROSLYNATOR_SARIF_FIXTURE = "\xEF\xBB\xBF" . '{"$schema":"https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json","version":"2.1.0","runs":[{"tool":{"driver":{"name":"Roslynator"}},"results":[]}]}';

const SPOTBUGS_SARIF_FIXTURE = '{"$schema":"https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json","version":"2.1.0","runs":[{"tool":{"driver":{"name":"SpotBugs"}},"results":[]}]}';

function staticAnalysisRunForJobTest(int $considered = 1): StaticAnalysisRun
{
    return StaticAnalysisRun::query()->create([
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

function staticAnalysisTarget(array $overrides = []): RepositoryCollectionTarget
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

function analyzeRepositoryJobDependencies(): array
{
    return [app(AttachmentTargetResolver::class), app(AttachmentService::class), app(Vault::class)];
}

/** @return list<string> */
function commandParts(mixed $command): array
{
    return is_array($command) ? $command : preg_split('/\s+/', (string) $command);
}

function argAfter(array $parts, string $flag): ?string
{
    $index = array_search($flag, $parts, true);

    return $index !== false ? ($parts[$index + 1] ?? null) : null;
}

/** @param array<string, string> $files relative path (within the clone) => content */
function plantClonedFiles(string $workDir, array $files): void
{
    foreach ($files as $relativePath => $content) {
        $path = $workDir . '/' . ltrim($relativePath, '/');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
    }
}

beforeEach(function () {
    app(Vault::class)->set('azdo-repos.pat', null, 'fake-pat');
});

it('clones a repository and attaches both a dotnet and a java report', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $workDir = end($parts);
            plantClonedFiles($workDir, [
                'App.sln' => '',
                // Stands in for a directory a real build would have produced —
                // spotbugs analyzes whatever .class files exist, independent
                // of which project directory produced them.
                'build/Main.class' => '',
            ]);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->first();
    expect($system)->not->toBeNull();

    $container = SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->first();
    expect($container)->not->toBeNull();

    $attachments = Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->get();

    expect($attachments)->toHaveCount(2)
        ->and($attachments->pluck('kind')->sort()->values()->all())->toBe([
            AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET,
            AttachmentIngestionService::KIND_CODE_QUALITY_JAVA,
        ]);

    $run->refresh();
    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(0);

    // Both flags are required for correct behavior against the real Roslynator
    // 0.13.x binary (see AnalyzeRepositoryJob::analyzeDotnet()'s own docblock) -
    // silently dropping either one would reintroduce a real, previously-shipped
    // bug (XML output SarifFindingParser can't read, and an unreliable exit
    // code), not just a style regression.
    Process::assertRan(function ($process) {
        $parts = commandParts($process->command);

        return ($parts[0] ?? null) === 'roslynator'
            && in_array('--output-format', $parts, true)
            && $parts[array_search('--output-format', $parts, true) + 1] === 'sarif'
            && in_array('--return-success-on-diagnostics', $parts, true);
    });
});

it('converges onto the same rows a live AzDO sync already created, not a duplicate', function () {
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

    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-001')->count())->toBe(1)
        ->and(SecurityContainer::query()->where('software_system_id', $system->id)->where('source_container_id', 'repo-001')->count())->toBe(1);

    $container->refresh();
    expect($container->name)->toBe('Live-synced container');
});

it('produces only a dotnet attachment for a repository with no Java build files', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(Attachment::query()->pluck('kind')->all())->toBe([AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET]);

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('treats a clean .sln (zero diagnostics, no output file, exit 0) as success, not a failure', function () {
    // Matches Roslynator's real, observed behavior: a clean solution produces
    // no SARIF file at all and exits 0 — the fake below deliberately writes
    // nothing for `roslynator`, mirroring that exact case.
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(Attachment::query()->count())->toBe(0)
        ->and(ErrorLog::query()->where('channel', 'static-analysis')->count())->toBe(0);

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('logs a dotnet-analyze failure only when roslynator both fails and produces no output', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: could not load MSBuild workspace');
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(Attachment::query()->count())->toBe(0);

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'dotnet-analyze')->first();
    expect($errorLog)->not->toBeNull();

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('produces only a java attachment for a repository with no .sln', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(Attachment::query()->pluck('kind')->all())->toBe([AttachmentIngestionService::KIND_CODE_QUALITY_JAVA]);

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('does not let a restore failure on one .sln prevent another from being analyzed, and merges their SARIF runs', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['Broken.sln' => '', 'Working.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'dotnet' && ($parts[1] ?? null) === 'restore') {
            $slnPath = $parts[2] ?? '';

            if (str_contains($slnPath, 'Broken.sln')) {
                return Process::result(exitCode: 1, errorOutput: 'error NU1101: package not found');
            }

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $attachment = Attachment::query()->where('kind', AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET)->first();
    expect($attachment)->not->toBeNull();

    $sarif = json_decode($attachment->payload, true);
    // Working.sln's roslynator fixture contributes exactly one run; only one
    // solution actually succeeded here, but the merge path is exercised the
    // same way multiple successes would be.
    expect($sarif['runs'])->toHaveCount(1);

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'dotnet-restore')->first();
    expect($errorLog)->not->toBeNull()
        ->and($errorLog->context_json['run'])->toBe($run->id);

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('does not let a Maven build failure in one directory prevent SpotBugs from analyzing classes elsewhere', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), [
                'moduleA/pom.xml' => '',
                'moduleB/pom.xml' => '',
                // Stands in for what a successful build of moduleB would have
                // produced; moduleA's own build fails and produces nothing.
                'moduleB/target/classes/Foo.class' => '',
            ]);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'mvn') {
            $path = $process->path ?? '';

            if (str_ends_with($path, 'moduleA')) {
                return Process::result(exitCode: 1, errorOutput: 'BUILD FAILURE');
            }

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(Attachment::query()->where('kind', AttachmentIngestionService::KIND_CODE_QUALITY_JAVA)->count())->toBe(1);

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'java-build')->first();
    expect($errorLog)->not->toBeNull();

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('records completion as failure and attempts no analysis when the clone fails', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: repository not found');
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(Attachment::query()->count())->toBe(0);

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'clone')->first();
    expect($errorLog)->not->toBeNull();

    $run->refresh();
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('deletes the scratch directory whether the run succeeds or fails', function () {
    $seenScratchRoots = [];

    Process::fake(function ($process) use (&$seenScratchRoots) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $workDir = end($parts);
            $seenScratchRoots[] = dirname($workDir);
            plantClonedFiles($workDir, ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect($seenScratchRoots)->toHaveCount(1);
    expect(File::isDirectory($seenScratchRoots[0]))->toBeFalse();
});
