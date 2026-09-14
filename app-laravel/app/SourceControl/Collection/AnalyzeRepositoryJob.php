<?php

namespace App\SourceControl\Collection;

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Assets\AzDoOwnerLookup;
use App\Assets\AzDoScanResultDtoFactory;
use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRepositoryState;
use App\Models\StaticAnalysisRun;
use App\Models\ToolInvocation;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Clones one Azure DevOps repository, runs Roslynator against every `*.sln`
 * found (.NET) and SpotBugs + Find Security Bugs against every directory
 * with compiled `.class` files (Java), and stores each SARIF produced as an
 * Attachment on the matching SoftwareSystem/SecurityContainer — reusing the
 * exact same AttachmentTargetResolver/AzDoScanResultDtoFactory/AttachmentService
 * path App\SourceControl\Collection\CollectRepositoryJob already uses, so
 * results converge onto the same rows a live AzDO sync, `-SbomScan`,
 * `-StaticAnalysis`, or CollectRepositoryJob already created or will later
 * create.
 *
 * Runs on the dedicated `static-analysis` queue, consumed only by the
 * isolated `static-analysis-collector` container/image — never the app
 * container's own default-queue worker, and never `collector`'s
 * `repository-collection` queue.
 *
 * Before cloning anything, the remote head is compared against the commit
 * recorded by the last clean pass (StaticAnalysisRepositoryState): an
 * unchanged repository is skipped outright, since static analysis findings
 * are a pure function of the code plus the analyser toolchain. Because the
 * toolchain is not fingerprinted, a collector image whose analysers or rules
 * changed needs one `force` sweep to pick them up.
 */
final class AnalyzeRepositoryJob implements ShouldQueue
{
    use Batchable, Dispatchable, Queueable;

    public int $tries = 3;

    /**
     * Overall job budget: dotnet restore (600s) + build (900s) + analyze
     * (900s) per .sln, potentially repeated across several solutions, plus
     * an independent java build (900s) + analyze (900s) pass. A repository
     * with an unusually large number of solutions can still exceed this —
     * collect-static-analysis.sh accepts the same unbounded-per-repo risk
     * today (only per-step timeouts exist there too); not a new gap this
     * job introduces.
     */
    public int $timeout = 10800;

    /**
     * Set by logFailure(): once any stage of this repository's pass has
     * failed, the analysed commit is not persisted, so the next sweep retries
     * the repository instead of freezing it out until someone pushes to it.
     */
    private bool $degraded = false;

    /**
     * Set true the moment any of Opengrep, dotnet/Roslynator, or
     * Java/SpotBugs actually completed a scan against this repository's code
     * — clean or with findings, as opposed to failing or finding no
     * applicable toolchain. Read by persistAnalyzedCommit() alongside
     * $degraded: caching requires both that at least one ecosystem completed
     * a scan (this flag) and that nothing failed ($degraded stays false).
     * Finding no toolchain does not set $degraded and does not, on its own,
     * prevent caching — it only means this flag stays false for that one
     * ecosystem; caching is still blocked only if EVERY ecosystem ends up in
     * that state (or any ecosystem fails).
     */
    private bool $anyAnalyzerCompleted = false;

    public function __construct(
        public readonly RepositoryCollectionTarget $target,
        public readonly int $staticAnalysisRunId,
        public readonly bool $force = false,
    ) {}

    public function handle(
        AttachmentTargetResolver $resolver,
        AttachmentService $attachments,
        Vault $vault,
    ): void {
        $pat = $vault->get('azdo-repos.pat', null)
            ?? throw new RuntimeException('AzDO Repos PAT not configured.');

        $scratchRoot = rtrim((string) config('static_analysis_collection.workspace_path'), '/')
            . '/' . (string) Str::uuid();
        $homeDir = $scratchRoot . '/home';
        $workDir = $scratchRoot . '/work';
        $cloned = false;

        // The whole block is guarded so that every exit — including the skip
        // path, which never clones — still deletes the scratch root the PAT
        // was just written into. The credential files must never survive.
        try {
            $this->prepareGitCredentials($pat, $homeDir);

            $remoteHead = $this->remoteHeadSha($homeDir);

            // An unreachable remote is a failure, exactly like a clone failure.
            if ($remoteHead->unreachable) {
                $this->recordCompletion(failed: true);

                return;
            }

            if (! $this->force && $this->isUnchangedSinceLastAnalysis($remoteHead->sha)) {
                $this->recordSkippedUnchanged();
                $this->recordCompletion(failed: false, skipped: true);

                return;
            }

            $cloned = $this->cloneRepository($homeDir, $workDir);

            if ($cloned) {
                $securityContainer = $this->resolveOwner($resolver);

                $this->analyzeOpengrep($attachments, $securityContainer, $workDir, $scratchRoot, $homeDir);
                $this->analyzeDotnet($attachments, $securityContainer, $workDir, $scratchRoot, $homeDir);
                $this->analyzeJava($attachments, $securityContainer, $workDir, $scratchRoot, $homeDir);

                $this->persistAnalyzedCommit($securityContainer, $workDir, $homeDir);
            }
        } finally {
            File::deleteDirectory($scratchRoot);
        }

        // A clone failure is a logged, per-repository outcome, not a job-level
        // exception — mirrors CollectRepositoryJob::handle()'s own reasoning.
        $this->recordCompletion(failed: ! $cloned || $this->degraded);
    }

    /**
     * Laravel's queue failure hook — called exactly once, only after every
     * retry attempt (tries=3) has been exhausted. Mirrors
     * CollectRepositoryJob::failed().
     */
    public function failed(Throwable $exception): void
    {
        $this->recordCompletion(failed: true);
    }

    /**
     * Marks this one repository as done against its parent StaticAnalysisRun,
     * under a row lock so concurrent AnalyzeRepositoryJob instances never lose
     * an increment to a race. Mirrors CollectRepositoryJob::recordCompletion()
     * exactly, scoped to StaticAnalysisRun.
     */
    private function recordCompletion(bool $failed, bool $skipped = false): void
    {
        DB::transaction(function () use ($failed, $skipped): void {
            $run = StaticAnalysisRun::query()->lockForUpdate()->find($this->staticAnalysisRunId);

            if (! $run instanceof StaticAnalysisRun || $run->status !== 'running') {
                return;
            }

            /** @var array{repositories_considered?: int, repositories_completed?: int, repositories_failed?: int, repositories_skipped?: int, repositories_skipped_by_reason?: array<string, int>, repositories_excluded_pre_dispatch?: int, repositories_excluded_by_reason?: array<string, int>} $storedCounts */
            $storedCounts = (array) $run->counts_json;

            $considered = (int) ($storedCounts['repositories_considered'] ?? 0);
            // A skipped repository still counts as completed: the run's own
            // completion condition below must keep working untouched.
            $completed = (int) ($storedCounts['repositories_completed'] ?? 0) + 1;
            $failedCount = (int) ($storedCounts['repositories_failed'] ?? 0) + ($failed ? 1 : 0);
            $skippedCount = (int) ($storedCounts['repositories_skipped'] ?? 0) + ($skipped ? 1 : 0);

            /** @var array<string, int> $skippedByReason */
            $skippedByReason = (array) ($storedCounts['repositories_skipped_by_reason'] ?? []);

            if ($skipped) {
                $skippedByReason['unchanged_commit'] = (int) ($skippedByReason['unchanged_commit'] ?? 0) + 1;
            }

            $update = [
                'counts_json' => [
                    'repositories_considered' => $considered,
                    'repositories_completed' => $completed,
                    'repositories_failed' => $failedCount,
                    'repositories_skipped' => $skippedCount,
                    'repositories_skipped_by_reason' => $skippedByReason,
                    'repositories_excluded_pre_dispatch' => (int) ($storedCounts['repositories_excluded_pre_dispatch'] ?? 0),
                    'repositories_excluded_by_reason' => (array) ($storedCounts['repositories_excluded_by_reason'] ?? []),
                ],
            ];

            if ($completed >= $considered) {
                $update['status'] = match (true) {
                    $failedCount === 0 => 'success',
                    $failedCount >= $considered => 'failure',
                    default => 'partial',
                };
                $update['finished_at'] = now();
            }

            $run->update($update);
        });
    }

    private function prepareGitCredentials(string $pat, string $homeDir): void
    {
        File::makeDirectory($homeDir, 0700, true, true);

        // PAT never appears in argv or shell history — git reads it from the
        // credential store, scoped to this job's own fake $HOME so concurrent
        // instances of this job in the same collector container never race on
        // a shared .netrc/.git-credentials.
        File::put($homeDir . '/.netrc', "machine dev.azure.com\n  login azdo\n  password {$pat}\n");
        chmod($homeDir . '/.netrc', 0600);
        File::put($homeDir . '/.git-credentials', "https://:{$pat}@dev.azure.com\n");
        chmod($homeDir . '/.git-credentials', 0600);

        Process::env(['HOME' => $homeDir])
            ->timeout(60)
            ->run(['git', 'config', '--global', 'credential.helper', 'store'])
            ->throw();
    }

    private function cloneRepository(string $homeDir, string $workDir): bool
    {
        $result = Process::env(['HOME' => $homeDir])
            ->timeout(600)
            ->run(['git', 'clone', '--quiet', '--depth', '1', '--no-tags', '--shallow-submodules', $this->target->repositoryCloneUrl, $workDir]);

        if ($result->failed()) {
            $this->logFailure('clone', $this->tail($result->errorOutput()));

            return false;
        }

        return true;
    }

    /**
     * One cheap credentialed round trip against the remote, before anything
     * is cloned: `ls-remote <url> HEAD` returns the tip of the remote default
     * branch, which is exactly the commit a `--depth 1` clone would check
     * out. Cloning first and comparing afterwards would still pay the clone
     * for every repository.
     */
    private function remoteHeadSha(string $homeDir): RemoteHead
    {
        $result = Process::env(['HOME' => $homeDir])
            ->timeout(120)
            ->run(['git', 'ls-remote', '--quiet', $this->target->repositoryCloneUrl, 'HEAD']);

        if ($result->failed()) {
            $this->logFailure('ls-remote', $this->tail($result->errorOutput()));

            return RemoteHead::unreachable();
        }

        $sha = $this->parseSha($result->output());

        // An empty repository legitimately reports no head. Falling through to
        // the full analysis costs a wasted pass, never a missed one.
        return $sha === null ? RemoteHead::none() : RemoteHead::at($sha);
    }

    /**
     * The first whitespace-separated field of the first non-empty line,
     * accepted only when it is a full 40-character hexadecimal object name.
     */
    private function parseSha(string $output): ?string
    {
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $candidate = explode(' ', str_replace("\t", ' ', $line), 2)[0];

            return strlen($candidate) === 40 && ctype_xdigit($candidate) ? $candidate : null;
        }

        return null;
    }

    /**
     * Resolves the container read-only — deliberately not via resolveOwner(),
     * which creates rows — on the same natural keys that resolver uses.
     */
    private function findExistingContainer(): ?SecurityContainer
    {
        return SecurityContainer::query()
            ->whereHas('softwareSystem', function (Builder $query): void {
                /** @var Builder<SoftwareSystem> $query */
                $query->where('source_id', AzDoNormalizer::SOURCE_ID)
                    ->where('source_system_id', $this->target->projectId);
            })
            ->where('source_container_id', $this->target->repositoryId)
            ->first();
    }

    private function isUnchangedSinceLastAnalysis(?string $remoteSha): bool
    {
        if ($remoteSha === null) {
            return false;
        }

        $state = $this->findExistingContainer()?->staticAnalysisState;

        return $state instanceof StaticAnalysisRepositoryState
            && strcasecmp($state->commit_sha, $remoteSha) === 0;
    }

    /**
     * Written at the point handle() takes the unchanged-commit skip: three
     * rows, one per tool, so "was tool Y ever considered for repository X"
     * has a row to find even on a skip. The container is guaranteed to
     * resolve here — isUnchangedSinceLastAnalysis() only returned true
     * because findExistingContainer() already found one with a
     * StaticAnalysisRepositoryState, and nothing writes to
     * security_containers in between.
     */
    private function recordSkippedUnchanged(): void
    {
        $containerId = $this->findExistingContainer()?->id;
        $now = now();

        foreach (['opengrep', 'dotnet-roslynator', 'java-spotbugs'] as $tool) {
            $this->recordToolInvocation($containerId, $tool, null, 'skipped_unchanged', null, $now, $now, null);
        }
    }

    /**
     * Read from the clone rather than reusing the ls-remote value: this is
     * the commit that was actually analysed, even if the branch moved in
     * between. Persisted only after a pass in which no stage logged a
     * failure — see $degraded.
     */
    private function persistAnalyzedCommit(SecurityContainer $container, string $workDir, string $homeDir): void
    {
        if ($this->degraded || ! $this->anyAnalyzerCompleted) {
            return;
        }

        $result = Process::path($workDir)
            ->env(['HOME' => $homeDir])
            ->timeout(60)
            ->run(['git', 'rev-parse', 'HEAD']);

        if ($result->failed()) {
            return;
        }

        $sha = $this->parseSha($result->output());

        if ($sha === null) {
            return;
        }

        StaticAnalysisRepositoryState::query()->updateOrCreate(
            ['security_container_id' => $container->id],
            [
                'commit_sha' => $sha,
                'analyzed_at' => now(),
                'analyzed_run_id' => $this->staticAnalysisRunId,
            ],
        );
    }

    private function resolveOwner(AttachmentTargetResolver $resolver): SecurityContainer
    {
        $system = AzDoScanResultDtoFactory::system([
            'projectId' => $this->target->projectId,
            'project' => $this->target->projectName,
            'projectDescription' => $this->target->projectDescription,
            'projectUrl' => $this->target->projectUrl,
        ]);

        $container = AzDoScanResultDtoFactory::container([
            'repositoryId' => $this->target->repositoryId,
            'repository' => $this->target->repositoryName,
            'defaultBranch' => $this->target->defaultBranch,
            'webUrl' => $this->target->repositoryCloneUrl,
            'repositoryWebUrl' => $this->target->repositoryBrowseUrl,
        ]);

        $owner = $resolver->resolveSystem(
            AzDoNormalizer::SOURCE_ID,
            $this->target->projectId,
            $this->target->projectName,
            url: $system->url,
            description: $system->description,
            metadata: $system->metadata ?? [],
        );

        return $resolver->resolveContainer(
            $owner,
            $this->target->repositoryId,
            $this->target->repositoryName,
            'repository',
            url: $container->url,
            metadata: $container->metadata ?? [],
        );
    }

    /**
     * Runs Opengrep once per cloned repository against the vendored,
     * version-pinned ruleset (csharp/java/javascript/typescript) — source-level,
     * no build required, so unlike analyzeDotnet()/analyzeJava() this runs
     * independent of what the repository actually contains. The SARIF report is
     * attached even when it carries zero results (unlike the other two
     * ecosystems' own zero-diagnostics case), so StaleRecordSweeper still
     * resolves any previously reported opengrep findings that no longer occur.
     */
    private function analyzeOpengrep(
        AttachmentService $attachments,
        SecurityContainer $container,
        string $workDir,
        string $scratchRoot,
        string $homeDir,
    ): void {
        $sarifPath = $scratchRoot . '/opengrep.sarif';
        $command = [
            'opengrep', 'scan', '--quiet', '--sarif',
            '--output', $sarifPath,
            '-f', (string) config('static_analysis_collection.opengrep_rules_dir'),
            $workDir,
        ];
        $startedAt = now();

        $result = Process::env(['HOME' => $homeDir])
            ->timeout((int) config('static_analysis_collection.opengrep_timeout'))
            ->run($command);

        if ($result->failed() || ! File::exists($sarifPath)) {
            $outputTail = $this->tail($result->errorOutput() . $result->output());
            $this->logFailure('opengrep-analyze', $outputTail);
            $this->recordToolInvocation($container->id, 'opengrep', $command, 'failed', $result->exitCode(), $startedAt, now(), $outputTail);

            return;
        }

        $this->anyAnalyzerCompleted = true;

        $this->recordToolInvocation($container->id, 'opengrep', $command, $this->sarifOutcome($sarifPath), $result->exitCode(), $startedAt, now(), null);

        $attachments->attachTo(
            owner: $container,
            kind: AttachmentIngestionService::KIND_CODE_QUALITY_OPENGREP,
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.opengrep.sarif',
            payload: File::get($sarifPath),
            createdByCommand: 'static-analysis',
            sourceRoot: $workDir,
        );
    }

    /**
     * Every *.sln found is restored, then (regardless of build's own result,
     * matching collect-static-analysis.sh) built and analyzed with
     * Roslynator. Every solution that produces a non-empty SARIF file is
     * merged into a single code-quality-dotnet Attachment. A solution with
     * zero diagnostics produces no output file at all (Roslynator's own
     * behavior) — not a failure, just nothing to merge for that solution.
     *
     * `--output-format sarif` is required explicitly: Roslynator 0.13.x
     * defaults to its own XML report format, not SARIF, when the flag is
     * omitted (regardless of the `--output` file's extension).
     * `--return-success-on-diagnostics` is required so the process exit code
     * stays a reliable failure signal — without it, Roslynator exits
     * non-zero both when diagnostics are found (the common, successful case)
     * and on a genuine analysis error, making the two indistinguishable by
     * exit code alone.
     */
    private function analyzeDotnet(
        AttachmentService $attachments,
        SecurityContainer $container,
        string $workDir,
        string $scratchRoot,
        string $homeDir,
    ): void {
        $env = ['HOME' => $homeDir];
        $runs = [];
        $schema = null;
        $version = null;

        $solutions = iterator_to_array((new Finder)->files()->in($workDir)->name('*.sln'), false);

        if ($solutions === []) {
            $this->logNoToolchain('dotnet', $container, 'dotnet-roslynator');

            return;
        }

        foreach ($solutions as $solution) {
            $slnPath = $solution->getPathname();
            $startedAt = now();

            $restoreCommand = ['dotnet', 'restore', $slnPath];

            $restoreResult = Process::env($env)
                ->timeout((int) config('static_analysis_collection.dotnet_restore_timeout'))
                ->run($restoreCommand);

            if ($restoreResult->failed()) {
                $outputTail = $this->tail($restoreResult->errorOutput() . $restoreResult->output());
                $this->logFailure('dotnet-restore', $outputTail, $slnPath);
                $this->recordToolInvocation($container->id, 'dotnet-roslynator', $restoreCommand, 'failed', $restoreResult->exitCode(), $startedAt, now(), $outputTail);

                continue;
            }

            $buildResult = Process::env($env)
                ->timeout((int) config('static_analysis_collection.dotnet_build_timeout'))
                ->run(['dotnet', 'build', '--no-restore', $slnPath]);

            if ($buildResult->failed()) {
                $this->logFailure('dotnet-build', $this->tail($buildResult->errorOutput() . $buildResult->output()), $slnPath);
            }

            $sarifPath = $scratchRoot . '/' . Str::uuid() . '.roslynator.sarif';

            $analyzeCommand = [
                'roslynator', 'analyze', $slnPath,
                '--output', $sarifPath,
                '--output-format', 'sarif',
                '--severity-level', 'info',
                '--return-success-on-diagnostics',
            ];

            $analyzeResult = Process::env($env)
                ->timeout((int) config('static_analysis_collection.analysis_timeout'))
                ->run($analyzeCommand);

            if ($analyzeResult->failed()) {
                $outputTail = $this->tail($analyzeResult->errorOutput() . $analyzeResult->output());
                $this->logFailure('dotnet-analyze', $outputTail, $slnPath);
                $this->recordToolInvocation($container->id, 'dotnet-roslynator', $analyzeCommand, 'failed', $analyzeResult->exitCode(), $startedAt, now(), $outputTail);

                continue;
            }

            $this->anyAnalyzerCompleted = true;

            // A clean, zero-diagnostic solution produces no output file at all — not
            // a failure, simply nothing to merge for this solution.
            if (! File::exists($sarifPath) || File::size($sarifPath) === 0) {
                $this->recordToolInvocation($container->id, 'dotnet-roslynator', $analyzeCommand, 'ran_clean', $analyzeResult->exitCode(), $startedAt, now(), null);

                continue;
            }

            $decoded = json_decode($this->stripUtf8Bom(File::get($sarifPath)), true);

            if (! is_array($decoded) || ! isset($decoded['runs'][0])) {
                $message = 'Roslynator produced output that could not be parsed as SARIF.';
                $this->logFailure('dotnet-analyze', $message, $slnPath);
                $this->recordToolInvocation($container->id, 'dotnet-roslynator', $analyzeCommand, 'failed', $analyzeResult->exitCode(), $startedAt, now(), $message);

                continue;
            }

            $results = $decoded['runs'][0]['results'] ?? [];
            $this->recordToolInvocation($container->id, 'dotnet-roslynator', $analyzeCommand, $results === [] ? 'ran_clean' : 'ran_with_findings', $analyzeResult->exitCode(), $startedAt, now(), null);

            if ($schema === null) {
                $schema = $decoded['$schema'] ?? 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';
                $version = $decoded['version'] ?? '2.1.0';
            }

            $runs[] = $decoded['runs'][0];
        }

        if ($runs === []) {
            return;
        }

        $attachments->attachTo(
            owner: $container,
            kind: AttachmentIngestionService::KIND_CODE_QUALITY_DOTNET,
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.dotnet.sarif',
            payload: json_encode(['$schema' => $schema, 'version' => $version, 'runs' => $runs], JSON_THROW_ON_ERROR),
            createdByCommand: 'static-analysis',
            sourceRoot: $workDir,
        );
    }

    /**
     * Every topmost pom.xml/build.gradle[.kts] directory is built
     * independently and non-fatally; afterward every directory anywhere in
     * the clone with compiled *.class files (independent of which
     * directories were actually built) is analyzed together in one
     * SpotBugs + Find Security Bugs run.
     */
    private function analyzeJava(
        AttachmentService $attachments,
        SecurityContainer $container,
        string $workDir,
        string $scratchRoot,
        string $homeDir,
    ): void {
        $env = ['HOME' => $homeDir];

        $projectDirs = $this->javaProjectDirs($workDir);

        if ($projectDirs === [] && $this->javaClassDirs($workDir) === []) {
            $this->logNoToolchain('java', $container, 'java-spotbugs');

            return;
        }

        foreach ($projectDirs as $projectDir) {
            $this->buildJavaProject($projectDir, $env);
        }

        $classDirs = $this->javaClassDirs($workDir);

        if ($classDirs === []) {
            return;
        }

        $sarifPath = $scratchRoot . '/spotbugs.sarif';
        $findSecBugsJar = $this->findSecBugsJar();

        $command = array_merge(
            ['spotbugs', '-textui', '-sarif', '-output', $sarifPath],
            $findSecBugsJar !== null ? ['-pluginList', $findSecBugsJar] : [],
            $classDirs,
        );

        $startedAt = now();

        $result = Process::env($env)
            ->timeout((int) config('static_analysis_collection.analysis_timeout'))
            ->run($command);

        if ($result->failed() || ! File::exists($sarifPath) || File::size($sarifPath) === 0) {
            $outputTail = $this->tail($result->errorOutput() . $result->output());
            $this->logFailure('java-analyze', $outputTail);
            $this->recordToolInvocation($container->id, 'java-spotbugs', $command, 'failed', $result->exitCode(), $startedAt, now(), $outputTail);

            return;
        }

        $this->anyAnalyzerCompleted = true;

        $this->recordToolInvocation($container->id, 'java-spotbugs', $command, $this->sarifOutcome($sarifPath), $result->exitCode(), $startedAt, now(), null);

        $attachments->attachTo(
            owner: $container,
            kind: AttachmentIngestionService::KIND_CODE_QUALITY_JAVA,
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.java.sarif',
            payload: File::get($sarifPath),
            createdByCommand: 'static-analysis',
            sourceRoot: $workDir,
        );
    }

    /**
     * The repo root first; only if it has none does a shallow (depth < 3)
     * recursive search run, collecting every such directory found — mirrors
     * collect-static-analysis.sh's find_java_project_dirs().
     *
     * @return list<string>
     */
    private function javaProjectDirs(string $workDir): array
    {
        foreach (['pom.xml', 'build.gradle', 'build.gradle.kts'] as $buildFile) {
            if (File::exists($workDir . '/' . $buildFile)) {
                return [$workDir];
            }
        }

        $dirs = [];

        foreach ((new Finder)->files()->in($workDir)->depth('< 3')->name(['pom.xml', 'build.gradle', 'build.gradle.kts']) as $file) {
            $dirs[$file->getPath()] = true;
        }

        return array_keys($dirs);
    }

    /** @param array<string, string> $env */
    private function buildJavaProject(string $dir, array $env): void
    {
        $timeout = (int) config('static_analysis_collection.java_build_timeout');
        $mvnw = $dir . '/mvnw';
        $gradlew = $dir . '/gradlew';

        $command = match (true) {
            is_file($mvnw) && is_executable($mvnw) => [$mvnw, '--batch-mode', '--quiet', '-DskipTests', 'compile'],
            File::exists($dir . '/pom.xml') => ['mvn', '--batch-mode', '--quiet', '-DskipTests', 'compile'],
            is_file($gradlew) && is_executable($gradlew) => [$gradlew, '--quiet', 'compileJava'],
            File::exists($dir . '/build.gradle') || File::exists($dir . '/build.gradle.kts') => ['gradle', '--quiet', 'compileJava'],
            default => null,
        };

        if ($command === null) {
            return;
        }

        $result = Process::path($dir)->env($env)->timeout($timeout)->run($command);

        if ($result->failed()) {
            $this->logFailure('java-build', $this->tail($result->errorOutput() . $result->output()), $dir);
        }
    }

    /** @return list<string> */
    private function javaClassDirs(string $workDir): array
    {
        if (! File::isDirectory($workDir)) {
            return [];
        }

        $dirs = [];

        foreach ((new Finder)->files()->in($workDir)->name('*.class') as $file) {
            $dirs[$file->getPath()] = true;
        }

        return array_keys($dirs);
    }

    /** Version-agnostic — matches collect-static-analysis.sh's own `find -iname` lookup. */
    private function findSecBugsJar(): ?string
    {
        $dir = (string) config('static_analysis_collection.findsecbugs_plugin_dir');

        if (! File::isDirectory($dir)) {
            return null;
        }

        foreach ((new Finder)->files()->in($dir)->depth('== 0')->name('findsecbugs-plugin-*.jar') as $file) {
            return $file->getPathname();
        }

        return null;
    }

    private function tail(string $output, int $length = 2000): string
    {
        return mb_strlen($output) > $length ? '…' . mb_substr($output, -$length) : $output;
    }

    /**
     * Roslynator's SARIF output is written with a leading UTF-8 byte-order
     * mark, which json_decode() does not tolerate (it returns null rather
     * than skipping it). SpotBugs' own SARIF output carries no such BOM.
     */
    private function stripUtf8Bom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    private function logNoToolchain(string $stage, SecurityContainer $container, string $tool): void
    {
        // Unlike logFailure()'s context (whose 'subject' field is genuinely
        // nullable), every field here is non-nullable, so no array_filter()
        // is needed to drop null entries.
        $context = [
            'run' => $this->staticAnalysisRunId,
            'project_id' => $this->target->projectId,
            'project_name' => $this->target->projectName,
            'repository_id' => $this->target->repositoryId,
            'repository_name' => $this->target->repositoryName,
            'stage' => $stage,
        ];

        Log::channel('single')->info("No applicable {$stage} toolchain found; {$stage} analysis skipped.", $context);

        $now = now();
        $this->recordToolInvocation($container->id, $tool, null, 'skipped_no_toolchain', null, $now, $now, null);
    }

    /**
     * Shared by analyzeOpengrep() and analyzeJava(): decodes a SARIF file the
     * same way analyzeDotnet() decodes Roslynator's own SARIF, treating
     * output that succeeded but could not be parsed as the safer, more
     * visible 'ran_with_findings' default rather than a new failure path.
     */
    private function sarifOutcome(string $path): string
    {
        $decoded = json_decode($this->stripUtf8Bom(File::get($path)), true);

        if (! is_array($decoded) || ! isset($decoded['runs'][0])) {
            return 'ran_with_findings';
        }

        $results = $decoded['runs'][0]['results'] ?? [];

        return $results === [] ? 'ran_clean' : 'ran_with_findings';
    }

    /**
     * @param  list<string>|null  $command
     */
    private function recordToolInvocation(
        ?int $securityContainerId,
        string $tool,
        ?array $command,
        string $outcome,
        ?int $exitCode,
        Carbon $startedAt,
        ?Carbon $finishedAt,
        ?string $outputTail,
    ): void {
        ToolInvocation::query()->create([
            'run_type' => StaticAnalysisRun::class,
            'run_id' => $this->staticAnalysisRunId,
            'security_container_id' => $securityContainerId,
            'tool' => $tool,
            'tool_version' => null,
            'command_json' => $command,
            'outcome' => $outcome,
            'exit_code' => $exitCode,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $finishedAt !== null ? max(0, $finishedAt->getTimestamp() - $startedAt->getTimestamp()) : null,
            'output_tail' => $outputTail !== null ? $this->tail($outputTail) : null,
        ]);
    }

    private function logFailure(string $stage, string $message, ?string $subject = null, ?Throwable $exception = null): void
    {
        $this->degraded = true;

        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'static-analysis',
            ...app(AzDoOwnerLookup::class)->forAzDoRepository($this->target->projectId, $this->target->repositoryId),
            'message' => $message,
            'context_json' => array_filter([
                'run' => $this->staticAnalysisRunId,
                'project_id' => $this->target->projectId,
                'project_name' => $this->target->projectName,
                'repository_id' => $this->target->repositoryId,
                'repository_name' => $this->target->repositoryName,
                'stage' => $stage,
                'subject' => $subject,
            ], fn (mixed $value): bool => $value !== null),
            'trace' => $exception?->getTraceAsString(),
            'occurred_at' => now(),
        ]);
    }
}
