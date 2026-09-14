<?php

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Credentials\Vault;
use App\Models\Attachment;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRepositoryState;
use App\Models\StaticAnalysisRun;
use App\Models\ToolInvocation;
use App\SourceControl\Collection\AnalyzeRepositoryJob;
use App\SourceControl\Collection\RepositoryCollectionTarget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

// Leading UTF-8 BOM matches Roslynator's real --output-format sarif output exactly
// (confirmed against the actual 0.13.1 binary) — every test using this fixture also
// exercises AnalyzeRepositoryJob::stripUtf8Bom(), which json_decode() requires.
const ROSLYNATOR_SARIF_FIXTURE = "\xEF\xBB\xBF" . '{"$schema":"https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json","version":"2.1.0","runs":[{"tool":{"driver":{"name":"Roslynator"}},"results":[]}]}';

const SPOTBUGS_SARIF_FIXTURE = '{"$schema":"https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json","version":"2.1.0","runs":[{"tool":{"driver":{"name":"SpotBugs"}},"results":[]}]}';

// Opengrep runs unconditionally on every cloned repository (no build/detection step, unlike
// dotnet/java), so every fake below needs to answer it. This zero-result fixture is what a
// clean scan actually writes (unlike Roslynator, Opengrep still produces a file with zero
// diagnostics) and is the default response used by every test that isn't specifically
// exercising Opengrep's own behavior.
const OPENGREP_SARIF_FIXTURE = '{"$schema":"https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json","version":"2.1.0","runs":[{"tool":{"driver":{"name":"opengrep"}},"results":[]}]}';

const OPENGREP_SARIF_WITH_FINDING_FIXTURE = '{"$schema":"https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json","version":"2.1.0","runs":[{"tool":{"driver":{"name":"opengrep","rules":[{"id":"javascript.lang.security.detect-eval.detect-eval","shortDescription":{"text":"Detected eval of user input"}}]}},"results":[{"ruleId":"javascript.lang.security.detect-eval.detect-eval","level":"error","message":{"text":"User input flows into eval()."},"locations":[{"physicalLocation":{"artifactLocation":{"uri":"src/index.js"},"region":{"startLine":10,"endLine":10}}}]}]}]}';

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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);

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

    expect($attachments)->toHaveCount(3)
        ->and($attachments->pluck('kind')->sort()->values()->all())->toBe([
            AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET,
            AttachmentIngestionService::KIND_CODE_QUALITY_JAVA,
            AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP,
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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // Opengrep always runs too (asserted separately) — scoped here to the
    // dotnet/java distinction this test is actually about.
    expect(Attachment::query()->whereIn('kind', [AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET, AttachmentIngestionService::KIND_CODE_QUALITY_JAVA])->pluck('kind')->all())
        ->toBe([AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET]);

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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // Opengrep still attaches its own (zero-result) report; only dotnet produced nothing.
    expect(Attachment::query()->count())->toBe(1)
        ->and(Attachment::query()->value('kind'))->toBe(AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP)
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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // Opengrep still attaches its own report; dotnet's failure produced no attachment.
    expect(Attachment::query()->count())->toBe(1)
        ->and(Attachment::query()->value('kind'))->toBe(AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP);

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'dotnet-analyze')->first();

    // Same context key shape as CollectRepositoryJob's identical failure kind
    // (project_id/project_name/repository_id/repository_name), not the
    // project-id-less shape this channel previously used.
    expect($errorLog)->not->toBeNull()
        ->and($errorLog->context_json['project_id'])->toBe('project-001')
        ->and($errorLog->context_json['project_name'])->toBe('SecurityProject')
        ->and($errorLog->context_json['repository_id'])->toBe('repo-001')
        ->and($errorLog->context_json['repository_name'])->toBe('backend-api');

    $run->refresh();
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

it('records the owning system/container on a logged static-analysis failure', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
    ]);

    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: could not load MSBuild workspace');
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'dotnet-analyze')->firstOrFail();

    expect($errorLog->software_system_id)->toBe($system->id)
        ->and($errorLog->security_container_id)->toBe($container->id);
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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // Opengrep always runs too (asserted separately) — scoped here to the
    // dotnet/java distinction this test is actually about.
    expect(Attachment::query()->whereIn('kind', [AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET, AttachmentIngestionService::KIND_CODE_QUALITY_JAVA])->pluck('kind')->all())
        ->toBe([AttachmentIngestionService::KIND_CODE_QUALITY_JAVA]);

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('logs a no-toolchain outcome for dotnet and creates no ErrorLog when no .sln exists anywhere', function () {
    Log::spy();
    // The chained Log::channel('single')->info(...) call needs channel() to
    // return the same spy so the subsequent info() call is recorded on it —
    // a spy returns null from unconfigured methods, so this must be set up
    // before the job runs, not asserted only afterward.
    Log::shouldReceive('channel')->with('single')->andReturnSelf();

    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            // A .class file with no .sln anywhere: only the dotnet no-toolchain
            // path is exercised — java still has something to analyze.
            plantClonedFiles(end($parts), ['build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(ErrorLog::query()->where('channel', 'static-analysis')->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with(
            Mockery::pattern('/No applicable dotnet toolchain found/'),
            Mockery::on(fn (array $context): bool => ($context['stage'] ?? null) === 'dotnet'
                && $context['project_id'] === 'project-001'
                && $context['repository_id'] === 'repo-001'),
        )
        ->once();

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('logs a no-toolchain outcome for java and creates no ErrorLog when no build files or classes exist', function () {
    Log::spy();
    // See the dotnet test above: channel('single') must be configured to
    // return the spy itself before the job runs, not asserted after.
    Log::shouldReceive('channel')->with('single')->andReturnSelf();

    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            // A .sln with no Java build file/class anywhere: only the java
            // no-toolchain path is exercised — dotnet still has something to analyze.
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(ErrorLog::query()->where('channel', 'static-analysis')->count())->toBe(0);

    Log::shouldHaveReceived('info')
        ->with(
            Mockery::pattern('/No applicable java toolchain found/'),
            Mockery::on(fn (array $context): bool => ($context['stage'] ?? null) === 'java'
                && $context['project_id'] === 'project-001'
                && $context['repository_id'] === 'repo-001'),
        )
        ->once();

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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
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
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_failed'])->toBe(1);
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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
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
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_failed'])->toBe(1);
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

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect($seenScratchRoots)->toHaveCount(1);
    expect(File::isDirectory($seenScratchRoots[0]))->toBeFalse();
});

it('attaches an opengrep report with findings', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['src/index.js' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_WITH_FINDING_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $attachment = Attachment::query()->where('kind', AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP)->first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->name)->toBe('backend-api.opengrep.sarif');

    $sarif = json_decode($attachment->payload, true);
    expect($sarif['runs'][0]['results'])->toHaveCount(1);

    $run->refresh();
    expect($run->status)->toBe('success');

    Process::assertRan(function ($process) {
        $parts = commandParts($process->command);

        return ($parts[0] ?? null) === 'opengrep'
            && ($parts[1] ?? null) === 'scan'
            && in_array('--sarif', $parts, true)
            && in_array('-f', $parts, true);
    });
});

it('attaches an opengrep report even when the scan finds zero results', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['src/index.js' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // A zero-result scan is still attached — StaleRecordSweeper needs a complete pass to
    // resolve any previously reported opengrep findings that no longer occur.
    expect(Attachment::query()->where('kind', AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP)->count())->toBe(1);

    $run->refresh();
    expect($run->status)->toBe('success');
});

it('logs an opengrep-analyze failure and still runs the dotnet and java analyzers', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $workDir = end($parts);
            plantClonedFiles($workDir, ['App.sln' => '', 'build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            return Process::result(exitCode: 1, errorOutput: 'opengrep: rule parse error');
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

    expect(Attachment::query()->where('kind', AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP)->exists())->toBeFalse()
        ->and(Attachment::query()->pluck('kind')->sort()->values()->all())->toBe([
            AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET,
            AttachmentIngestionService::KIND_CODE_QUALITY_JAVA,
        ]);

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'opengrep-analyze')->first();
    expect($errorLog)->not->toBeNull();

    $run->refresh();
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_failed'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Skipping repositories whose head commit has not moved
// ---------------------------------------------------------------------------

const UNCHANGED_HEAD_SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4';
const MOVED_HEAD_SHA = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c';

function seedAnalyzedRepository(?string $commitSha): SecurityContainer
{
    $system = SoftwareSystem::factory()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'project-001',
    ]);

    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
    ]);

    if ($commitSha !== null) {
        StaticAnalysisRepositoryState::query()->create([
            'security_container_id' => $container->id,
            'commit_sha' => $commitSha,
            'analyzed_at' => now()->subDay(),
            'analyzed_run_id' => null,
        ]);
    }

    return $container;
}

/**
 * A fake in which every stage succeeds: the clone plants a .sln and a .class
 * directory, and all three analysers write their zero-result fixture. The two
 * git revision queries answer with the shas given (null = empty output, i.e.
 * a remote or clone that exposes no usable head).
 */
function staticAnalysisCleanPassFake(?string $remoteSha, ?string $analyzedSha = null): Closure
{
    return function ($process) use ($remoteSha, $analyzedSha) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: $remoteSha === null ? '' : "{$remoteSha}\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'rev-parse') {
            return Process::result(exitCode: 0, output: $analyzedSha === null ? '' : "{$analyzedSha}\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '', 'build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    };
}

function assertClonedRepository(): void
{
    Process::assertRan(fn ($process) => (commandParts($process->command)[0] ?? null) === 'git'
        && (commandParts($process->command)[1] ?? null) === 'clone');
}

it('skips a repository whose remote head equals the commit last analysed', function () {
    seedAnalyzedRepository(UNCHANGED_HEAD_SHA);

    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    Process::assertDidntRun(fn ($process) => (commandParts($process->command)[0] ?? null) === 'git'
        && (commandParts($process->command)[1] ?? null) === 'clone');

    expect(Attachment::query()->count())->toBe(0);

    $run->refresh();
    expect($run->status)->toBe('success')
        ->and($run->counts_json['repositories_completed'])->toBe(1)
        ->and($run->counts_json['repositories_skipped'])->toBe(1)
        ->and($run->counts_json['repositories_failed'])->toBe(0);
});

it('analyses a repository whose remote head has moved since the last analysis', function () {
    seedAnalyzedRepository(UNCHANGED_HEAD_SHA);

    Process::fake(staticAnalysisCleanPassFake(MOVED_HEAD_SHA, MOVED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    assertClonedRepository();

    expect(Attachment::query()->count())->toBe(3);

    $run->refresh();
    expect($run->counts_json['repositories_skipped'])->toBe(0);
});

it('analyses a repository that has no recorded scan state at all', function () {
    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA, UNCHANGED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    assertClonedRepository();

    $run->refresh();
    expect($run->counts_json['repositories_skipped'])->toBe(0);
});

it('re-analyses a repository with an unchanged head when the sweep is forced', function () {
    seedAnalyzedRepository(UNCHANGED_HEAD_SHA);

    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA, UNCHANGED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id, force: true))
        ->handle(...analyzeRepositoryJobDependencies());

    assertClonedRepository();

    expect(Attachment::query()->count())->toBe(3);

    $run->refresh();
    expect($run->counts_json['repositories_skipped'])->toBe(0)
        ->and($run->counts_json['repositories_completed'])->toBe(1);
});

it('persists the analysed commit read from the clone after a clean pass', function () {
    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA, MOVED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $container = SecurityContainer::query()->where('source_container_id', 'repo-001')->firstOrFail();
    $states = StaticAnalysisRepositoryState::query()->get();

    // The clone's own HEAD, not the ls-remote value: that is the commit the
    // analysers actually saw, even if the branch moved in between.
    expect($states)->toHaveCount(1)
        ->and($states->first()->security_container_id)->toBe($container->id)
        ->and($states->first()->commit_sha)->toBe(MOVED_HEAD_SHA)
        ->and($states->first()->analyzed_run_id)->toBe($run->id)
        ->and($states->first()->analyzed_at)->not->toBeNull();
});

it('does not persist the analysed commit when a stage failed, so the next sweep retries', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'rev-parse') {
            return Process::result(exitCode: 0, output: MOVED_HEAD_SHA . "\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['App.sln' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            return Process::result(exitCode: 1, errorOutput: 'fatal: could not load MSBuild workspace');
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect(ErrorLog::query()->where('context_json->stage', 'dotnet-analyze')->exists())->toBeTrue()
        ->and(StaticAnalysisRepositoryState::query()->count())->toBe(0);
});

it('updates the existing scan state rather than adding a second row', function () {
    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA, UNCHANGED_HEAD_SHA));

    $firstRun = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $firstRun->id))
        ->handle(...analyzeRepositoryJobDependencies());

    Process::fake(staticAnalysisCleanPassFake(MOVED_HEAD_SHA, MOVED_HEAD_SHA));

    $secondRun = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $secondRun->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $states = StaticAnalysisRepositoryState::query()->get();

    expect($states)->toHaveCount(1)
        ->and($states->first()->commit_sha)->toBe(MOVED_HEAD_SHA)
        ->and($states->first()->analyzed_run_id)->toBe($secondRun->id);
});

it('counts an unreachable remote as a failed repository and never clones it', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 128, errorOutput: 'fatal: could not read from remote repository');
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    Process::assertDidntRun(fn ($process) => (commandParts($process->command)[0] ?? null) === 'git'
        && (commandParts($process->command)[1] ?? null) === 'clone');

    $errorLog = ErrorLog::query()->where('channel', 'static-analysis')->where('context_json->stage', 'ls-remote')->first();

    expect($errorLog)->not->toBeNull();

    $run->refresh();
    expect($run->status)->toBe('failure')
        ->and($run->counts_json['repositories_failed'])->toBe(1)
        ->and($run->counts_json['repositories_skipped'])->toBe(0);
});

it('deletes the PAT-bearing scratch directory on the skip path too', function () {
    $workspace = storage_path('app/private/static-analysis-skip-path-test');
    File::deleteDirectory($workspace);
    config(['static_analysis_collection.workspace_path' => $workspace]);

    seedAnalyzedRepository(UNCHANGED_HEAD_SHA);

    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // The job wrote .netrc/.git-credentials under $workspace/<uuid>/home before
    // deciding to skip; nothing of it may survive the job.
    expect(File::directories($workspace))->toBe([]);

    File::deleteDirectory($workspace);
});

// ---------------------------------------------------------------------------
// Only caching a commit when at least one analyzer actually completed a scan
// (#486) — $anyAnalyzerCompleted, checked alongside $degraded in
// persistAnalyzedCommit(). Each of the four combinations below also runs the
// job a second time against an unchanged remote head, to prove whether the
// unchanged-commit skip path (isUnchangedSinceLastAnalysis()) is taken next.
// ---------------------------------------------------------------------------

it('case 1: caches, and later skip-caches, a repository where only opengrep finds applicable code', function () {
    $cloneCount = 0;

    Process::fake(function ($process) use (&$cloneCount) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'rev-parse') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $cloneCount++;
            // No .sln, no Java build file, and no compiled classes anywhere:
            // a pure single-ecosystem repository (e.g. Python/JS) that only
            // opengrep ever analyzes.
            plantClonedFiles(end($parts), ['src/index.js' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $container = SecurityContainer::query()->where('source_container_id', 'repo-001')->firstOrFail();

    // Opengrep alone completing a scan is enough to cache the commit — this
    // is the pure-Python/JS repository this story must not regress.
    expect(StaticAnalysisRepositoryState::query()->where('security_container_id', $container->id)->count())->toBe(1)
        ->and($cloneCount)->toBe(1);

    $secondRun = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $secondRun->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $secondRun->refresh();

    // The remote head has not moved: commit-based skip-caching still works
    // for a single-ecosystem repository exactly as it does today.
    expect($cloneCount)->toBe(1)
        ->and($secondRun->counts_json['repositories_skipped'])->toBe(1)
        ->and($secondRun->status)->toBe('success');
});

it('case 2: does not cache, and does not skip-cache, a repository where java fails alongside a successful opengrep pass', function () {
    $cloneCount = 0;

    Process::fake(function ($process) use (&$cloneCount) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'rev-parse') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $cloneCount++;
            // No .sln: only java (via its compiled classes) and opengrep are
            // in play for this repository.
            plantClonedFiles(end($parts), ['build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            return Process::result(exitCode: 1, errorOutput: 'spotbugs: analysis error');
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // Opengrep succeeded, but java's own analyze step failed: $degraded stays
    // true, so — exactly as today — nothing is cached.
    expect(StaticAnalysisRepositoryState::query()->count())->toBe(0)
        ->and($cloneCount)->toBe(1);

    $run->refresh();
    expect($run->status)->toBe('failure');

    $secondRun = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $secondRun->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // No cached state exists, so the second sweep re-clones and re-attempts
    // every ecosystem rather than taking the unchanged-commit skip path.
    expect($cloneCount)->toBe(2);

    $secondRun->refresh();
    expect($secondRun->counts_json['repositories_skipped'])->toBe(0)
        ->and($secondRun->status)->toBe('failure');
});

it('case 3: does not cache, and does not skip-cache, a repository where no analyzer completes a scan at all', function () {
    $cloneCount = 0;

    Process::fake(function ($process) use (&$cloneCount) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'rev-parse') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $cloneCount++;
            // No .sln, no Java build file, and no compiled classes anywhere,
            // and opengrep itself fails: nothing at all scans this repository.
            plantClonedFiles(end($parts), ['README.md' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            return Process::result(exitCode: 1, errorOutput: 'opengrep: rule parse error');
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    // Every applicable ecosystem either found no toolchain (dotnet, java) or
    // failed (opengrep): nothing scanned this repository's code, so it must
    // not be cached — this is the new behaviour this story adds.
    expect(StaticAnalysisRepositoryState::query()->count())->toBe(0)
        ->and($cloneCount)->toBe(1);

    $run->refresh();
    expect($run->status)->toBe('failure');

    $secondRun = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $secondRun->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect($cloneCount)->toBe(2);

    $secondRun->refresh();
    expect($secondRun->counts_json['repositories_skipped'])->toBe(0);
});

it('case 4: caches, and later skip-caches, a repository where every ecosystem completes a clean scan', function () {
    $cloneCount = 0;

    Process::fake(function ($process) use (&$cloneCount) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'ls-remote') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\tHEAD\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'rev-parse') {
            return Process::result(exitCode: 0, output: UNCHANGED_HEAD_SHA . "\n");
        }

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            $cloneCount++;
            plantClonedFiles(end($parts), ['App.sln' => '', 'build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'roslynator') {
            File::put(argAfter($parts, '--output'), ROSLYNATOR_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $container = SecurityContainer::query()->where('source_container_id', 'repo-001')->firstOrFail();

    expect(StaticAnalysisRepositoryState::query()->where('security_container_id', $container->id)->count())->toBe(1)
        ->and($cloneCount)->toBe(1);

    $run->refresh();
    expect($run->status)->toBe('success');

    $secondRun = staticAnalysisRunForJobTest();
    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $secondRun->id))
        ->handle(...analyzeRepositoryJobDependencies());

    expect($cloneCount)->toBe(1);

    $secondRun->refresh();
    expect($secondRun->counts_json['repositories_skipped'])->toBe(1)
        ->and($secondRun->status)->toBe('success');
});

// ---------------------------------------------------------------------------
// ToolInvocation rows (#489) — one per tool invocation (or applicable
// no-toolchain/skip outcome) per repository, on every outcome, not only on
// failure.
// ---------------------------------------------------------------------------

it('records an opengrep ToolInvocation row with outcome ran_clean when the scan finds zero results', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            plantClonedFiles(end($parts), ['src/index.js' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $invocation = ToolInvocation::query()->where('tool', 'opengrep')->first();

    expect($invocation)->not->toBeNull()
        ->and($invocation->outcome)->toBe('ran_clean')
        ->and($invocation->run_type)->toBe(StaticAnalysisRun::class)
        ->and($invocation->run_id)->toBe($run->id);
});

it('records a dotnet-roslynator ToolInvocation row with outcome skipped_no_toolchain and no exit_code when no .sln exists', function () {
    Process::fake(function ($process) {
        $parts = commandParts($process->command);

        if (($parts[0] ?? null) === 'git' && ($parts[1] ?? null) === 'clone') {
            // A .class file with no .sln anywhere: only the dotnet no-toolchain
            // path is exercised — java still has something to analyze.
            plantClonedFiles(end($parts), ['build/Main.class' => '']);

            return Process::result(exitCode: 0);
        }

        if (($parts[0] ?? null) === 'spotbugs') {
            File::put(argAfter($parts, '-output'), SPOTBUGS_SARIF_FIXTURE);
        }

        if (($parts[0] ?? null) === 'opengrep') {
            File::put(argAfter($parts, '--output'), OPENGREP_SARIF_FIXTURE);
        }

        return Process::result(exitCode: 0);
    });

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $invocation = ToolInvocation::query()->where('tool', 'dotnet-roslynator')->first();

    expect($invocation)->not->toBeNull()
        ->and($invocation->outcome)->toBe('skipped_no_toolchain')
        ->and($invocation->exit_code)->toBeNull();
});

it('records three skipped_unchanged ToolInvocation rows, one per tool, with the existing container id when a repository is skipped as unchanged', function () {
    $container = seedAnalyzedRepository(UNCHANGED_HEAD_SHA);

    Process::fake(staticAnalysisCleanPassFake(UNCHANGED_HEAD_SHA));

    $run = staticAnalysisRunForJobTest();

    (new AnalyzeRepositoryJob(staticAnalysisTarget(), $run->id))
        ->handle(...analyzeRepositoryJobDependencies());

    $invocations = ToolInvocation::query()->where('outcome', 'skipped_unchanged')->get();

    expect($invocations)->toHaveCount(3)
        ->and($invocations->pluck('tool')->sort()->values()->all())->toBe(['dotnet-roslynator', 'java-spotbugs', 'opengrep'])
        ->and($invocations->pluck('security_container_id')->unique()->all())->toBe([$container->id])
        ->and($invocations->pluck('run_type')->unique()->all())->toBe([StaticAnalysisRun::class]);
});
