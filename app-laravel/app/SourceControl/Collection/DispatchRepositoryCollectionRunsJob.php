<?php

namespace App\SourceControl\Collection;

use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Contracts\SourceControlProvider;
use App\Sources\Context\SourceContextFacts;
use App\Sync\SystemIntegrationRuntime;
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
            $message = 'Azure DevOps Repos credential is not configured.';

            $run->update([
                'status' => 'failure',
                'finished_at' => now(),
                'error_message' => $message,
            ]);

            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'repository-collection',
                'message' => $message,
                'context_json' => [
                    'run' => $run->id,
                    'operation' => 'discover',
                ],
                'trace' => null,
                'occurred_at' => now(),
            ]);

            return;
        }

        $runtime->runSourceControl(self::SOURCE_CONTROL_ID, function (SourceControlProvider $resolvedProvider) use ($run): void {
            /** @var EnumeratesInventory&SourceControlProvider $resolvedProvider */
            $targets = $this->buildTargets($resolvedProvider, $run->id);

            if ($targets === []) {
                $run->update([
                    'status' => 'success',
                    'finished_at' => now(),
                    'counts_json' => ['repositories_considered' => 0],
                ]);

                return;
            }

            // Set before dispatch so every CollectRepositoryJob's own
            // completion bookkeeping (see recordCompletion() on that class)
            // knows the target count to compare against — the run finishes
            // itself once repositories_completed reaches this number. This
            // is deliberately not driven by Illuminate\Bus\Batch's own
            // then()/catch()/finally() callbacks: empirically, under the
            // `sync` queue connection, batched jobs run (their Attachments
            // are created) but the batch's own pending_jobs bookkeeping is
            // never decremented and those callbacks never fire — a
            // dependency this run-completion mechanism cannot afford.
            $run->update([
                'counts_json' => [
                    'repositories_considered' => count($targets),
                    'repositories_completed' => 0,
                    'repositories_failed' => 0,
                ],
            ]);

            $batchName = 'repository-collection:' . $run->id;

            try {
                $batch = Bus::batch(array_map(
                    fn (RepositoryCollectionTarget $target): CollectRepositoryJob => new CollectRepositoryJob($target, $run->id),
                    $targets,
                ))
                    ->name($batchName)
                    ->onQueue('repository-collection')
                    ->allowFailures()
                    ->dispatch();

                $run->update(['batch_id' => $batch->id]);
            } catch (Throwable $e) {
                // The `sync` queue connection re-throws after a job's own
                // failed() hook already ran (see CollectRepositoryJob::
                // recordCompletion()) — if every repository was already
                // accounted for before this exception propagated here, the
                // run has already correctly finished; do not overwrite it.
                $run->refresh();

                if ($run->status === 'running') {
                    $run->update([
                        'status' => 'failure',
                        'finished_at' => now(),
                        'error_message' => $e->getMessage(),
                    ]);

                    ErrorLog::query()->create([
                        'level' => 'error',
                        'channel' => 'repository-collection',
                        'message' => $e->getMessage(),
                        'context_json' => [
                            'run' => $run->id,
                            'operation' => 'dispatch',
                        ],
                        'trace' => $e->getTraceAsString(),
                        'occurred_at' => now(),
                    ]);
                }
            }
        });
    }

    /** @return list<RepositoryCollectionTarget> */
    private function buildTargets(EnumeratesInventory&SourceControlProvider $provider, int $runId): array
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
                            'run' => $runId,
                            'project_id' => $project->sourceSystemId,
                            'project_name' => $project->name,
                            'repository_id' => $container->sourceContainerId,
                            'repository_name' => $container->name,
                            'operation' => 'discover',
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
