<?php

namespace App\SourceControl\Collection;

use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Contracts\SourceControlProvider;
use App\Sources\Context\SourceContextFacts;
use App\Sync\SystemIntegrationRuntime;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enumerates every project and non-disabled repository the `azdo-repos`
 * Source Control provider's credential can see, and dispatches one
 * CollectRepositoryJob per repository as a single job batch on the
 * dedicated `repository-collection` queue, tracked via a
 * RepositoryCollectionRun row.
 *
 * Runs on the app's own default queue — it only makes Azure DevOps REST
 * API calls (the same cost profile as SyncInventoryJob), never the
 * repository-collection queue reserved for the isolated collector
 * container's git/trivy work.
 */
final class DispatchRepositoryCollectionRunsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    private const SOURCE_CONTROL_ID = 'azdo-repos';

    public function uniqueId(): string
    {
        return 'repository-collection';
    }

    public function handle(SystemIntegrationRuntime $runtime): void
    {
        $run = RepositoryCollectionRun::query()->create([
            'source_control_id' => self::SOURCE_CONTROL_ID,
            'started_at' => now(),
            'status' => 'running',
            'counts_json' => [],
        ]);

        $provider = $runtime->sourceControl(self::SOURCE_CONTROL_ID);

        if (! $provider instanceof EnumeratesInventory || ! $runtime->hasRequiredSystemCredentials($provider->credentialFields())) {
            $run->update([
                'status' => 'failure',
                'finished_at' => now(),
                'error_message' => 'Azure DevOps Repos credential is not configured.',
            ]);

            return;
        }

        $runtime->runSourceControl(self::SOURCE_CONTROL_ID, function (SourceControlProvider $resolvedProvider) use ($run): void {
            /** @var EnumeratesInventory&SourceControlProvider $resolvedProvider */
            $targets = $this->buildTargets($resolvedProvider);

            if ($targets === []) {
                $run->update([
                    'status' => 'success',
                    'finished_at' => now(),
                    'counts_json' => ['repositories_considered' => 0],
                ]);

                return;
            }

            $batchName = 'repository-collection:' . $run->id;

            try {
                Bus::batch(array_map(
                    fn (RepositoryCollectionTarget $target): CollectRepositoryJob => new CollectRepositoryJob($target),
                    $targets,
                ))
                    ->name($batchName)
                    ->onQueue('repository-collection')
                    ->allowFailures()
                    ->then(function (Batch $batch) use ($run): void {
                        $this->finishRunFromBatch($run, $batch->totalJobs, $batch->failedJobs);
                    })
                    ->catch(function (Batch $batch, Throwable $e) use ($run): void {
                        $this->finishRunFromBatch($run, $batch->totalJobs, $batch->failedJobs, $e->getMessage());
                    })
                    ->dispatch();
            } catch (Throwable) {
                // The `sync` queue connection (used in tests, and optionally
                // elsewhere) re-throws after recording a job's own failure,
                // unlike a real worker process, which never lets a job's
                // exception escape its own processing loop — allowFailures()
                // still protects the batch's bookkeeping, it just doesn't
                // stop dispatch() itself from throwing here. The batch row
                // is already created (job_batches, looked up by $batchName
                // below) before any job runs, so recovery continues there
                // rather than via this exception.
            }

            $storedBatch = DB::table('job_batches')->where('name', $batchName)->first();

            if ($storedBatch === null) {
                $run->refresh();

                if ($run->status === 'running') {
                    $run->update([
                        'status' => 'failure',
                        'finished_at' => now(),
                        'error_message' => 'The repository-collection batch could not be dispatched.',
                    ]);
                }

                return;
            }

            // Set unconditionally: update() only ever touches the batch_id
            // column here, so this is safe to run whether or not then()/
            // catch() already finished the run above.
            $run->update(['batch_id' => $storedBatch->id]);

            $run->refresh();

            if ($run->status === 'running' && (int) $storedBatch->pending_jobs === 0) {
                // Every job already ran (e.g. the `sync` queue connection
                // executes each job inline during dispatch()) but then()
                // never fired for this batch — finish the run directly from
                // the batch's own row rather than leaving it stuck
                // "running" forever. Under a real, async queue connection
                // pending_jobs is still > 0 here (jobs were only just
                // pushed), so this branch is skipped and the eventual
                // worker process's own then()/catch() invocation is what
                // finishes the run instead.
                $this->finishRunFromBatch($run, (int) $storedBatch->total_jobs, (int) $storedBatch->failed_jobs);
            }
        });
    }

    private function finishRunFromBatch(RepositoryCollectionRun $run, int $totalJobs, int $failedJobs, ?string $errorMessage = null): void
    {
        $run->update([
            'status' => $errorMessage === null ? 'success' : 'failure',
            'finished_at' => now(),
            'error_message' => $errorMessage,
            'counts_json' => [
                'repositories_considered' => $totalJobs,
                'repositories_failed' => $failedJobs,
            ],
        ]);
    }

    /** @return list<RepositoryCollectionTarget> */
    private function buildTargets(EnumeratesInventory&SourceControlProvider $provider): array
    {
        $targets = [];

        foreach ($provider->fetchProjects() as $project) {
            foreach ($provider->fetchRepositories($project) as $container) {
                if ($container->kind !== 'repository') {
                    continue;
                }

                $metadata = $container->metadata ?? [];
                $cloneUrl = SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL);

                if ($cloneUrl === null) {
                    ErrorLog::query()->create([
                        'level' => 'error',
                        'channel' => 'repository-collection',
                        'message' => 'Repository has no clone URL, skipping.',
                        'context_json' => [
                            'project' => $project->name,
                            'repository' => $container->name,
                        ],
                        'trace' => null,
                        'occurred_at' => now(),
                    ]);

                    continue;
                }

                $targets[] = new RepositoryCollectionTarget(
                    projectId: $project->sourceSystemId,
                    projectName: $project->name,
                    projectDescription: $project->description,
                    projectUrl: $project->url,
                    repositoryId: $container->sourceContainerId,
                    repositoryName: $container->name,
                    repositoryBrowseUrl: $container->url,
                    repositoryCloneUrl: $cloneUrl,
                    defaultBranch: SourceContextFacts::getString($metadata, SourceContextFacts::CODE_DEFAULT_BRANCH),
                );
            }
        }

        return $targets;
    }
}
