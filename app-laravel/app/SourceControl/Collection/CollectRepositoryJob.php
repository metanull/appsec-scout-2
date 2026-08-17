<?php

namespace App\SourceControl\Collection;

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Assets\AzDoScanResultDtoFactory;
use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
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
use Throwable;

/**
 * Clones one Azure DevOps repository, runs Trivy against it (SBOM as
 * CycloneDX, vulnerabilities and secrets as SARIF, against the shared
 * trivy-server), and stores every report Trivy produced as an Attachment on
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
     * Overall job budget: git config (60s) + clone (600s) + up to three
     * sequential Trivy scans (1200s each, see PER_SCAN_TIMEOUT) can approach
     * an hour for a large repository, so this must exceed their sum with room
     * to spare rather than matching any single step's own timeout.
     */
    public int $timeout = 5400;

    private const PER_SCAN_TIMEOUT = 1200;

    public function __construct(
        public readonly RepositoryCollectionTarget $target,
        public readonly int $repositoryCollectionRunId,
    ) {}

    public function handle(
        AttachmentTargetResolver $resolver,
        AttachmentService $attachments,
        Vault $vault,
    ): void {
        $pat = $vault->get('azdo-repos.pat', null)
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

                foreach ($this->reportKinds() as $report) {
                    $this->scanAndAttach($attachments, $securityContainer, $workDir, $scratchRoot, $report);
                }
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
        $this->recordCompletion(failed: ! $cloned);
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

    /** @return list<array{kind: string, extraArgs: list<string>, extension: string}> */
    private function reportKinds(): array
    {
        return [
            ['kind' => AttachmentIngestionService::KIND_SBOM, 'extraArgs' => [], 'extension' => 'cdx.json'],
            ['kind' => AttachmentIngestionService::KIND_VULNERABILITIES, 'extraArgs' => ['--scanners', 'vuln'], 'extension' => 'vuln.sarif.json'],
            ['kind' => AttachmentIngestionService::KIND_SECRETS, 'extraArgs' => ['--scanners', 'secret'], 'extension' => 'secrets.sarif.json'],
        ];
    }

    /** @param array{kind: string, extraArgs: list<string>, extension: string} $report */
    private function scanAndAttach(
        AttachmentService $attachments,
        SecurityContainer $securityContainer,
        string $workDir,
        string $scratchRoot,
        array $report,
    ): void {
        $trivyServerUrl = (string) config('repository_collection.trivy_server_url');
        $tokenFile = (string) config('repository_collection.trivy_token_file');
        $timeout = (string) config('repository_collection.trivy_timeout');

        if (! File::exists($tokenFile)) {
            $this->logFailure($report['kind'], 'Trivy token file not found at ' . $tokenFile);

            return;
        }

        $token = trim(File::get($tokenFile));
        $outputPath = $scratchRoot . '/' . $report['kind'] . '.' . $report['extension'];
        $format = $report['kind'] === AttachmentIngestionService::KIND_SBOM ? 'cyclonedx' : 'sarif';

        $command = array_merge(
            ['trivy', 'fs', '--server', $trivyServerUrl, '--token', $token, '--timeout', $timeout],
            $report['extraArgs'],
            ['--format', $format, '--output', $outputPath, $workDir],
        );

        try {
            $result = Process::timeout(self::PER_SCAN_TIMEOUT)->run($command);
        } catch (Throwable $e) {
            $this->logFailure($report['kind'], $e->getMessage());

            return;
        }

        if ($result->failed() || ! File::exists($outputPath) || File::size($outputPath) === 0) {
            $this->logFailure($report['kind'], 'Trivy scan did not produce output.');

            return;
        }

        $attachments->attachTo(
            owner: $securityContainer,
            kind: $report['kind'],
            mime: 'application/octet-stream',
            name: $this->target->repositoryName . '.' . $report['extension'],
            payload: File::get($outputPath),
            createdByCommand: 'repository-collection',
        );
    }

    private function logFailure(string $kind, string $message): void
    {
        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'repository-collection',
            'message' => $message,
            'context_json' => [
                'repository_id' => $this->target->repositoryId,
                'repository' => $this->target->repositoryName,
                'kind' => $kind,
            ],
            'trace' => null,
            'occurred_at' => now(),
        ]);
    }
}
