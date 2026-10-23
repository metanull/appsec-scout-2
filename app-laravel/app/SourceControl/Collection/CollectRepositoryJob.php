<?php

namespace App\SourceControl\Collection;

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Assets\AzDoOwnerLookup;
use App\Assets\AzDoScanResultDtoFactory;
use App\Credentials\RunWithSystemCredentials;
use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\ToolInvocation;
use App\SourceControl\Collection\Hygiene\RepositoryHygieneCheck;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
use XMLWriter;

/**
 * Clones one Azure DevOps repository, runs Trivy against it (SBOM as
 * CycloneDX, vulnerabilities and secrets as SARIF, against the shared
 * trivy-server; plus an SBOM and vulnerabilities of the committed
 * JAR/WAR/EAR archives when there are any), plus RepositoryHygieneCheck's
 * report of committed build output and binaries, and stores every report
 * produced as an Attachment on
 * the matching SoftwareSystem/SecurityContainer — reusing the exact same
 * AttachmentTargetResolver/AzDoScanResultDtoFactory/AttachmentService path
 * App\Assets\Sbom\PendingSbomScanImporter already uses for
 * `invoke-ops.ps1 -SbomScan`'s run.jsonl imports, so results converge onto
 * the same rows a live AzDO sync or that script already created.
 *
 * Runs on the dedicated `repository-collection` queue, consumed only by the
 * isolated `collector` container/image — never the app container's own
 * default-queue worker.
 */
final class CollectRepositoryJob implements ShouldQueue
{
    use Batchable, Dispatchable, Queueable;

    public int $tries = 3;

    /**
     * Overall job budget: git config (60s) + clone (600s) + up to five
     * sequential Trivy scans (three `fs`, two `rootfs` for committed
     * archives; 1200s each, see PER_SCAN_TIMEOUT) and the hygiene check's
     * `git ls-files` (300s, HYGIENE_TIMEOUT) can approach two hours for
     * a large repository, so this must exceed their sum rather than matching
     * any single step's own timeout. The `redis-collection` connection's
     * retry_after (config/queue.php) must stay above this value.
     */
    public int $timeout = 7200;

    private const PER_SCAN_TIMEOUT = 1200;

    private const HYGIENE_TIMEOUT = 300;

    /**
     * Set by logFailure(): once any Trivy scan against this repository has
     * failed, the repository counts as failed for this run even though its
     * clone succeeded.
     */
    private bool $degraded = false;

    public function __construct(
        public readonly RepositoryCollectionTarget $target,
        public readonly int $repositoryCollectionRunId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RunWithSystemCredentials];
    }

    public function handle(
        AttachmentTargetResolver $resolver,
        AttachmentService $attachments,
        Vault $vault,
        RepositoryHygieneCheck $hygiene,
    ): void {
        Log::info('Repository collection started.', $this->logContext('start'));

        $pat = $vault->getSystem('azdo-repos.pat')
            ?? throw new RuntimeException('AzDO Repos PAT not configured.');

        $scratchRoot = rtrim((string) config('repository_collection.workspace_path'), '/')
            . '/' . (string) Str::uuid();
        $homeDir = $scratchRoot . '/home';
        $workDir = $scratchRoot . '/work';
        $cloned = false;

        try {
            $cloned = $this->cloneRepository($pat, $homeDir, $workDir);

            if ($cloned) {
                $securityContainer = $this->resolveOwner($resolver);
                $trivyEnvironment = $this->trivyEnvironment($scratchRoot);

                $reportPaths = [];

                foreach ($this->reportKinds() as $report) {
                    $reportPaths[$report['kind']] = $this->scanAndAttach($attachments, $securityContainer, $workDir, $scratchRoot, $trivyEnvironment, $report);
                }

                if ($this->shouldScanArchives($securityContainer, $workDir)) {
                    foreach ($this->archiveReportKinds() as $report) {
                        $this->scanAndAttach($attachments, $securityContainer, $workDir, $scratchRoot, $trivyEnvironment, $report);
                    }
                }

                $this->checkHygiene($hygiene, $attachments, $securityContainer, $workDir, $reportPaths[AttachmentIngestionService::KIND_SECRETS] ?? null);
            }
        } finally {
            File::deleteDirectory($scratchRoot);
        }

        // A clone failure is a logged, per-repository outcome (like a Trivy
        // scan failure below), not a job-level exception: it must not
        // trigger Laravel's retry mechanism — a "repository not found" or
        // authentication failure will not succeed on attempt 2 or 3 — and
        // must not propagate out of dispatch(), which the sync queue
        // connection (tests, and optionally elsewhere) re-throws through
        // after already recording it via failed(), aborting the rest of
        // the batch under that connection specifically.
        $this->recordCompletion(failed: ! $cloned || $this->degraded);

        Log::info('Repository collection finished.', $this->logContext('finish', ['cloned' => $cloned]));
    }

    /**
     * Laravel's queue failure hook — called exactly once, only after every
     * retry attempt (tries=3) has been exhausted, never per-attempt. This
     * (not a try/finally inside handle()) is why completion is recorded
     * here rather than around the whole handle() body: a transient failure
     * that's about to retry must not count as this repository being "done"
     * yet.
     */
    public function failed(Throwable $exception): void
    {
        $context = $this->logContext('job');

        // Not Log::error() (the default `stack` channel already includes
        // the `database` handler at level=error) — that would create a
        // second, poorer-context ErrorLog row for the same failure.
        Log::channel('single')->error($exception->getMessage(), $context);

        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'repository-collection',
            ...app(AzDoOwnerLookup::class)->forAzDoRepository($this->target->projectId, $this->target->repositoryId),
            'message' => $exception->getMessage(),
            'context_json' => $context,
            'trace' => $exception->getTraceAsString(),
            'occurred_at' => now(),
        ]);

        $this->recordCompletion(failed: true);
    }

    /**
     * Marks this one repository as done against its parent
     * RepositoryCollectionRun, under a row lock so concurrent
     * CollectRepositoryJob instances (multiple collector workers finishing
     * at the same time) never lose an increment to a race. Once every
     * dispatched repository has been accounted for, finishes the run —
     * this is the run's only completion mechanism; it does not depend on
     * Illuminate\Bus\Batch's own then()/catch()/finally() callbacks, whose
     * pending_jobs bookkeeping was found not to fire reliably.
     */
    private function recordCompletion(bool $failed): void
    {
        DB::transaction(function () use ($failed): void {
            $run = RepositoryCollectionRun::query()->lockForUpdate()->find($this->repositoryCollectionRunId);

            if (! $run instanceof RepositoryCollectionRun || $run->status !== 'running') {
                return;
            }

            /** @var array{repositories_considered?: int, repositories_completed?: int, repositories_failed?: int, repositories_excluded_pre_dispatch?: int, repositories_excluded_by_reason?: array<string, int>} $storedCounts */
            $storedCounts = (array) $run->counts_json;

            $considered = (int) ($storedCounts['repositories_considered'] ?? 0);
            $completed = (int) ($storedCounts['repositories_completed'] ?? 0) + 1;
            $failedCount = (int) ($storedCounts['repositories_failed'] ?? 0) + ($failed ? 1 : 0);

            $update = [
                'counts_json' => [
                    'repositories_considered' => $considered,
                    'repositories_completed' => $completed,
                    'repositories_failed' => $failedCount,
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

        // Genuinely worth retrying if it fails — a local git/filesystem
        // setup problem, not a fact about the repository itself.
        Process::env(['HOME' => $homeDir])
            ->timeout(60)
            ->run(['git', 'config', '--global', 'credential.helper', 'store'])
            ->throw();

        $result = Process::env(['HOME' => $homeDir])
            ->timeout(600)
            ->run(['git', 'clone', '--quiet', '--depth', '1', '--no-tags', '--shallow-submodules', $this->target->repositoryCloneUrl, $workDir]);

        if ($result->failed()) {
            $this->logFailure('clone', "git clone failed for repository '{$this->target->repositoryName}': " . $result->errorOutput());

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

    /** @return list<array{kind: string, target: string, format: string, extraArgs: list<string>, extension: string}> */
    private function reportKinds(): array
    {
        return [
            ['kind' => AttachmentIngestionService::KIND_SBOM, 'target' => 'fs', 'format' => 'cyclonedx', 'extraArgs' => [], 'extension' => 'cdx.json'],
            ['kind' => AttachmentIngestionService::KIND_VULNERABILITIES, 'target' => 'fs', 'format' => 'sarif', 'extraArgs' => ['--scanners', 'vuln'], 'extension' => 'vuln.sarif.json'],
            ['kind' => AttachmentIngestionService::KIND_SECRETS, 'target' => 'fs', 'format' => 'sarif', 'extraArgs' => ['--scanners', 'secret'], 'extension' => 'secrets.sarif.json'],
        ];
    }

    /**
     * `trivy fs` reads manifests and lock files only, so committed JAR/WAR/EAR archives (a
     * vendored application server, a deployed .ear) go unscanned. `trivy rootfs` reads those
     * installed artifacts but no lock files, so it complements the `fs` scans rather than
     * replacing them. Its reports get kinds of their own, so each reconciles only its own
     * components and findings.
     *
     * @return list<array{kind: string, target: string, format: string, extraArgs: list<string>, extension: string}>
     */
    private function archiveReportKinds(): array
    {
        $javaDbArgs = $this->javaDbArgs();

        return [
            ['kind' => AttachmentIngestionService::KIND_SBOM_ARCHIVES, 'target' => 'rootfs', 'format' => 'cyclonedx', 'extraArgs' => $javaDbArgs, 'extension' => 'archives.cdx.json'],
            ['kind' => AttachmentIngestionService::KIND_VULNERABILITIES_ARCHIVES, 'target' => 'rootfs', 'format' => 'sarif', 'extraArgs' => ['--scanners', 'vuln', ...$javaDbArgs], 'extension' => 'archives.vuln.sarif.json'],
        ];
    }

    /**
     * Only the archive scans need Trivy's Java DB (to identify a JAR by its SHA-1), so only they
     * get its persistent cache directory and the configured Java DB repository.
     *
     * @return list<string>
     */
    private function javaDbArgs(): array
    {
        $args = [];
        $cacheDir = (string) config('repository_collection.trivy_cache_dir');
        $javaDbRepository = (string) config('repository_collection.trivy_java_db_repository');

        if ($cacheDir !== '') {
            array_push($args, '--cache-dir', $cacheDir);
        }

        if ($javaDbRepository !== '') {
            array_push($args, '--java-db-repository', $javaDbRepository);
        }

        return $args;
    }

    /**
     * Scans archives when the tree contains any, and also when an earlier collection stored an
     * archive report for this repository: the archives may since have been deleted, and only a
     * new (now empty) report resolves the findings they had. A repository that never held an
     * archive is scanned exactly as before.
     */
    private function shouldScanArchives(SecurityContainer $securityContainer, string $workDir): bool
    {
        return $this->containsArchives($workDir)
            || $securityContainer->attachments()->whereIn('kind', [
                AttachmentIngestionService::KIND_SBOM_ARCHIVES,
                AttachmentIngestionService::KIND_VULNERABILITIES_ARCHIVES,
            ])->exists();
    }

    private function containsArchives(string $workDir): bool
    {
        return Finder::create()
            ->files()
            ->in($workDir)
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->name('/\.(jar|war|ear)$/i')
            ->hasResults();
    }

    /**
     * Trivy reads Maven's global settings from $MAVEN_HOME/conf/settings.xml, so a per-job
     * MAVEN_HOME under the scratch root redirects `central` to the configured mirror without
     * touching HOME (which also holds Trivy's own cache directory).
     *
     * @return array<string, string>
     */
    private function trivyEnvironment(string $scratchRoot): array
    {
        $mirrorUrl = (string) config('repository_collection.maven_mirror_url');

        if ($mirrorUrl === '') {
            return [];
        }

        $mavenHome = $scratchRoot . '/maven';
        File::ensureDirectoryExists($mavenHome . '/conf');
        File::put($mavenHome . '/conf/settings.xml', $this->mavenMirrorSettings($mirrorUrl));

        return ['MAVEN_HOME' => $mavenHome];
    }

    private function mavenMirrorSettings(string $mirrorUrl): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('settings');
        $xml->startElement('mirrors');
        $xml->startElement('mirror');
        $xml->writeElement('id', 'appsec-scout-central-mirror');
        $xml->writeElement('mirrorOf', 'central');
        $xml->writeElement('url', $mirrorUrl);
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Reports the build output and binaries committed to the repository (see
     * RepositoryHygieneCheck). The report is stored when it has findings, and also when an earlier
     * collection stored one, so the now empty report resolves the findings of folders since
     * removed; a repository that never committed build output gets no attachment. A failure is
     * logged and counts the repository as failed, like a failed Trivy scan.
     *
     * @param  string|null  $secretsReportPath  this collection's Trivy secret report, when that scan succeeded
     */
    private function checkHygiene(
        RepositoryHygieneCheck $hygiene,
        AttachmentService $attachments,
        SecurityContainer $securityContainer,
        string $workDir,
        ?string $secretsReportPath,
    ): void {
        $command = $hygiene->command($workDir);
        $startedAt = now();

        try {
            $result = Process::timeout(self::HYGIENE_TIMEOUT)->run($command);

            if ($result->failed()) {
                $message = "git ls-files failed for repository '{$this->target->repositoryName}' (exit code {$result->exitCode()}): " . trim($result->errorOutput());
                $this->logFailure(RepositoryHygieneCheck::TOOL, $message, extra: ['exit_code' => $result->exitCode()]);
                $this->recordToolInvocation($securityContainer, RepositoryHygieneCheck::TOOL, $command, 'failed', $result->exitCode(), $startedAt, now(), $message);

                return;
            }

            $findings = $hygiene->findings($result->output(), $secretsReportPath === null ? null : File::get($secretsReportPath));
        } catch (Throwable $e) {
            $this->logFailure(RepositoryHygieneCheck::TOOL, $e->getMessage(), $e);
            $this->recordToolInvocation($securityContainer, RepositoryHygieneCheck::TOOL, $command, 'failed', null, $startedAt, now(), $e->getMessage());

            return;
        }

        $this->recordToolInvocation($securityContainer, RepositoryHygieneCheck::TOOL, $command, $findings === [] ? 'ran_clean' : 'ran_with_findings', $result->exitCode(), $startedAt, now(), null);

        if ($findings === [] && ! $securityContainer->attachments()->where('kind', AttachmentIngestionService::KIND_REPO_HYGIENE)->exists()) {
            return;
        }

        $attachments->attachTo(
            owner: $securityContainer,
            kind: AttachmentIngestionService::KIND_REPO_HYGIENE,
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.repo-hygiene.sarif.json',
            payload: $hygiene->report($findings),
            createdByCommand: 'repository-collection',
        );
    }

    /**
     * Returns the report's path once it is attached, null when the scan failed.
     *
     * @param  array<string, string>  $trivyEnvironment
     * @param  array{kind: string, target: string, format: string, extraArgs: list<string>, extension: string}  $report
     */
    private function scanAndAttach(
        AttachmentService $attachments,
        SecurityContainer $securityContainer,
        string $workDir,
        string $scratchRoot,
        array $trivyEnvironment,
        array $report,
    ): ?string {
        $trivyServerUrl = (string) config('repository_collection.trivy_server_url');
        $tokenFile = (string) config('repository_collection.trivy_token_file');
        $timeout = (string) config('repository_collection.trivy_timeout');
        $tool = $this->trivyToolName($report['kind']);
        $startedAt = now();

        if (! File::exists($tokenFile)) {
            $message = 'Trivy token file not found at ' . $tokenFile;
            $this->logFailure($report['kind'], $message);
            $this->recordToolInvocation($securityContainer, $tool, null, 'failed', null, $startedAt, now(), $message);

            return null;
        }

        $token = trim(File::get($tokenFile));
        $outputPath = $scratchRoot . '/' . $report['kind'] . '.' . $report['extension'];

        $command = array_merge(
            ['trivy', $report['target'], '--server', $trivyServerUrl, '--token', $token, '--timeout', $timeout],
            $report['extraArgs'],
            ['--format', $report['format'], '--output', $outputPath, $workDir],
        );

        try {
            $result = Process::env($trivyEnvironment)->timeout(self::PER_SCAN_TIMEOUT)->run($command);
        } catch (Throwable $e) {
            $this->logFailure($report['kind'], $e->getMessage(), $e);
            $this->recordToolInvocation($securityContainer, $tool, $command, 'failed', null, $startedAt, now(), $e->getMessage());

            return null;
        }

        if ($result->failed() || ! File::exists($outputPath) || File::size($outputPath) === 0) {
            $errorOutput = str_replace($token, '***REDACTED***', $result->errorOutput());
            $message = $this->scanFailureMessage($result->exitCode(), $errorOutput);
            $this->logFailure($report['kind'], $message, extra: ['exit_code' => $result->exitCode()]);
            $this->recordToolInvocation($securityContainer, $tool, $command, 'failed', $result->exitCode(), $startedAt, now(), trim($errorOutput) !== '' ? $errorOutput : $message);

            return null;
        }

        $finishedAt = now();

        $this->recordToolInvocation(
            $securityContainer,
            $tool,
            $command,
            $this->trivyOutcome($report['format'], $outputPath),
            $result->exitCode(),
            $startedAt,
            $finishedAt,
            null,
        );

        $attachment = $attachments->attachTo(
            owner: $securityContainer,
            kind: $report['kind'],
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.' . $report['extension'],
            payload: File::get($outputPath),
            createdByCommand: 'repository-collection',
        );

        Log::info('Repository collection report attached.', $this->logContext($report['kind'], [
            'attachment_id' => $attachment->id,
            'size_bytes' => $attachment->size_bytes,
        ]));

        return $outputPath;
    }

    /**
     * Trivy logs INFO/WARN lines first and ends a failed run with a FATAL entry (which may span
     * several lines), so the message keeps everything from the last FATAL on — or, when there is
     * none, the end of stderr.
     */
    private function scanFailureMessage(?int $exitCode, string $errorOutput): string
    {
        $message = "Trivy scan did not produce output (exit code {$exitCode}).";
        $fatalAt = strrpos($errorOutput, 'FATAL');
        $detail = trim($fatalAt === false ? $errorOutput : substr($errorOutput, $fatalAt));

        return $detail === '' ? $message : $message . ' ' . $this->tail($detail, 1000);
    }

    private function trivyToolName(string $kind): string
    {
        return match ($kind) {
            AttachmentIngestionService::KIND_SBOM => 'trivy-sbom',
            AttachmentIngestionService::KIND_VULNERABILITIES => 'trivy-vuln',
            AttachmentIngestionService::KIND_SECRETS => 'trivy-secret',
            AttachmentIngestionService::KIND_SBOM_ARCHIVES => 'trivy-sbom-archives',
            AttachmentIngestionService::KIND_VULNERABILITIES_ARCHIVES => 'trivy-vuln-archives',
            default => 'trivy',
        };
    }

    /**
     * The SBOM report has no "clean" state to detect — a CycloneDX inventory
     * is meaningful output regardless of how many components it lists — so it
     * always records as findings. The two SARIF report kinds are decoded the
     * same way AnalyzeRepositoryJob::analyzeDotnet() decodes Roslynator's own
     * SARIF: an unparseable-but-successful output is treated as the safer,
     * more visible 'ran_with_findings' default rather than a new failure path.
     */
    private function trivyOutcome(string $format, string $outputPath): string
    {
        if ($format === 'cyclonedx') {
            return 'ran_with_findings';
        }

        $decoded = json_decode($this->stripUtf8Bom(File::get($outputPath)), true);

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
        SecurityContainer $securityContainer,
        string $tool,
        ?array $command,
        string $outcome,
        ?int $exitCode,
        Carbon $startedAt,
        ?Carbon $finishedAt,
        ?string $outputTail,
    ): void {
        ToolInvocation::query()->create([
            'run_type' => RepositoryCollectionRun::class,
            'run_id' => $this->repositoryCollectionRunId,
            'security_container_id' => $securityContainer->id,
            'tool' => $tool,
            'tool_version' => null,
            'command_json' => $command !== null ? $this->redactCommand($command) : null,
            'outcome' => $outcome,
            'exit_code' => $exitCode,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $finishedAt !== null ? max(0, $finishedAt->getTimestamp() - $startedAt->getTimestamp()) : null,
            'output_tail' => $outputTail !== null ? $this->tail($outputTail) : null,
        ]);
    }

    /**
     * Never store the Trivy token: the value immediately following --token is
     * replaced before the command is persisted to command_json.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function redactCommand(array $command): array
    {
        $tokenIndex = array_search('--token', $command, true);

        if ($tokenIndex !== false && isset($command[$tokenIndex + 1])) {
            $command[$tokenIndex + 1] = '***REDACTED***';
        }

        return $command;
    }

    private function tail(string $output, int $length = 4000): string
    {
        return mb_strlen($output) > $length ? '…' . mb_substr($output, -$length) : $output;
    }

    /**
     * Trivy's SARIF output may carry a leading UTF-8 byte-order mark, which
     * json_decode() does not tolerate — identical in body to
     * AnalyzeRepositoryJob::stripUtf8Bom(), duplicated here since this file
     * has no existing helper to reuse.
     */
    private function stripUtf8Bom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /** @param  array<string, mixed>  $extra */
    private function logFailure(string $operation, string $message, ?Throwable $exception = null, array $extra = []): void
    {
        $this->degraded = true;

        $context = $this->logContext($operation, $extra);

        // Not Log::error() — see failed()'s identical comment.
        Log::channel('single')->error($message, $context);

        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'repository-collection',
            ...app(AzDoOwnerLookup::class)->forAzDoRepository($this->target->projectId, $this->target->repositoryId),
            'message' => $message,
            'context_json' => $context,
            'trace' => $exception?->getTraceAsString(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(string $operation, array $extra = []): array
    {
        return array_merge([
            'run' => $this->repositoryCollectionRunId,
            'batch_id' => $this->batch()?->id,
            'project_id' => $this->target->projectId,
            'project_name' => $this->target->projectName,
            'repository_id' => $this->target->repositoryId,
            'repository_name' => $this->target->repositoryName,
            'operation' => $operation,
        ], $extra);
    }
}
