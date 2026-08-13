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
                $batch = Bus::batch(array_map(
                    fn (RepositoryCollectionTarget $target): CollectRepositoryJob => new CollectRepositoryJob($target),
                    $targets,
                ))
                    ->name($batchName)
                    ->onQueue('repository-collection')
                    ->allowFailures()
                    // then() only fires when every job in the batch succeeds with
                    // zero failures — not what "the sweep completed" means here,
                    // since allowFailures() means one repository's clone/scan
                    // failure is expected and must not fail the whole run (see the
                    // epic's Cross-cutting constraints). finally() fires once the
                    // batch is done regardless of individual job outcomes, so the
                    // run's own status only reflects whether the sweep itself
                    // completed — repositories_failed carries the per-repository
                    // failure count separately. finally() runs once a worker
                    // (the isolated collector container, consuming the
                    // repository-collection queue) has processed every job in
                    // the batch — this handle() method returns long before that,
                    // since dispatching only pushes the jobs.
                    ->finally(function (Batch $batch) use ($run): void {
                        $this->finishRunFromBatch($run, $batch->totalJobs, $batch->failedJobs);
                    })
                    ->dispatch();

                $run->update(['batch_id' => $batch->id]);
            } catch (Throwable $e) {
                $run->update([
                    'status' => 'failure',
                    'finished_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Marks the run "success" — allowFailures() means the sweep itself
     * completing is what "success" means here, regardless of how many
     * individual repositories failed; that count is carried separately in
     * counts_json.repositories_failed. A "failure" run status is reserved
     * for the sweep never completing at all (missing credential, or the
     * batch failing to dispatch in the first place — see the two other
     * call sites in handle()).
     */
    private function finishRunFromBatch(RepositoryCollectionRun $run, int $totalJobs, int $failedJobs): void
    {
        $run->update([
            'status' => 'success',
            'finished_at' => now(),
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
