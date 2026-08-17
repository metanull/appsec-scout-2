<?php

namespace App\SourceControl\Collection;

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Assets\AzDoScanResultDtoFactory;
use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\StaticAnalysisRun;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public function __construct(
        public readonly RepositoryCollectionTarget $target,
        public readonly int $staticAnalysisRunId,
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

        try {
            $cloned = $this->cloneRepository($pat, $homeDir, $workDir);

            if ($cloned) {
                $securityContainer = $this->resolveOwner($resolver);

                $this->analyzeDotnet($attachments, $securityContainer, $workDir, $scratchRoot, $homeDir);
                $this->analyzeJava($attachments, $securityContainer, $workDir, $scratchRoot, $homeDir);
            }
        } finally {
            File::deleteDirectory($scratchRoot);
        }

        // A clone failure is a logged, per-repository outcome, not a job-level
        // exception — mirrors CollectRepositoryJob::handle()'s own reasoning.
        $this->recordCompletion(failed: ! $cloned);
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
    private function recordCompletion(bool $failed): void
    {
        DB::transaction(function () use ($failed): void {
            $run = StaticAnalysisRun::query()->lockForUpdate()->find($this->staticAnalysisRunId);

            if (! $run instanceof StaticAnalysisRun || $run->status !== 'running') {
                return;
            }

            /** @var array{repositories_considered?: int, repositories_completed?: int, repositories_failed?: int} $storedCounts */
            $storedCounts = (array) $run->counts_json;

            $considered = (int) ($storedCounts['repositories_considered'] ?? 0);
            $completed = (int) ($storedCounts['repositories_completed'] ?? 0) + 1;
            $failedCount = (int) ($storedCounts['repositories_failed'] ?? 0) + ($failed ? 1 : 0);

            $update = [
                'counts_json' => [
                    'repositories_considered' => $considered,
                    'repositories_completed' => $completed,
                    'repositories_failed' => $failedCount,
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

    private function cloneRepository(string $pat, string $homeDir, string $workDir): bool
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

        $result = Process::env(['HOME' => $homeDir])
            ->timeout(600)
            ->run(['git', 'clone', '--quiet', '--depth', '1', '--no-tags', '--shallow-submodules', $this->target->repositoryCloneUrl, $workDir]);

        if ($result->failed()) {
            $this->logFailure('clone', $this->tail($result->errorOutput()));

            return false;
        }

        return true;
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

        foreach ((new Finder)->files()->in($workDir)->name('*.sln') as $solution) {
            $slnPath = $solution->getPathname();

            $restoreResult = Process::env($env)
                ->timeout((int) config('static_analysis_collection.dotnet_restore_timeout'))
                ->run(['dotnet', 'restore', $slnPath]);

            if ($restoreResult->failed()) {
                $this->logFailure('dotnet-restore', $this->tail($restoreResult->errorOutput() . $restoreResult->output()), $slnPath);

                continue;
            }

            $buildResult = Process::env($env)
                ->timeout((int) config('static_analysis_collection.dotnet_build_timeout'))
                ->run(['dotnet', 'build', '--no-restore', $slnPath]);

            if ($buildResult->failed()) {
                $this->logFailure('dotnet-build', $this->tail($buildResult->errorOutput() . $buildResult->output()), $slnPath);
            }

            $sarifPath = $scratchRoot . '/' . Str::uuid() . '.roslynator.sarif';

            $analyzeResult = Process::env($env)
                ->timeout((int) config('static_analysis_collection.analysis_timeout'))
                ->run([
                    'roslynator', 'analyze', $slnPath,
                    '--output', $sarifPath,
                    '--output-format', 'sarif',
                    '--severity-level', 'info',
                    '--return-success-on-diagnostics',
                ]);

            if ($analyzeResult->failed()) {
                $this->logFailure('dotnet-analyze', $this->tail($analyzeResult->errorOutput() . $analyzeResult->output()), $slnPath);

                continue;
            }

            // A clean, zero-diagnostic solution produces no output file at all — not
            // a failure, simply nothing to merge for this solution.
            if (! File::exists($sarifPath) || File::size($sarifPath) === 0) {
                continue;
            }

            $decoded = json_decode($this->stripUtf8Bom(File::get($sarifPath)), true);

            if (! is_array($decoded) || ! isset($decoded['runs'][0])) {
                $this->logFailure('dotnet-analyze', 'Roslynator produced output that could not be parsed as SARIF.', $slnPath);

                continue;
            }

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

        foreach ($this->javaProjectDirs($workDir) as $projectDir) {
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

        $result = Process::env($env)
            ->timeout((int) config('static_analysis_collection.analysis_timeout'))
            ->run($command);

        if ($result->failed() || ! File::exists($sarifPath) || File::size($sarifPath) === 0) {
            $this->logFailure('java-analyze', $this->tail($result->errorOutput() . $result->output()));

            return;
        }

        $attachments->attachTo(
            owner: $container,
            kind: AttachmentIngestionService::KIND_CODE_QUALITY_JAVA,
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.java.sarif',
            payload: File::get($sarifPath),
            createdByCommand: 'static-analysis',
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

    private function logFailure(string $stage, string $message, ?string $subject = null): void
    {
        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'static-analysis',
            'message' => $message,
            'context_json' => array_filter([
                'run' => $this->staticAnalysisRunId,
                'repository_id' => $this->target->repositoryId,
                'repository' => $this->target->repositoryName,
                'stage' => $stage,
                'subject' => $subject,
            ], fn (mixed $value): bool => $value !== null),
            'trace' => null,
            'occurred_at' => now(),
        ]);
    }
}
