<?php

namespace App\SourceControl\Collection;

use App\Assets\AzDoOwnerLookup;
use App\Models\ErrorLog;
use App\Models\StaticAnalysisRun;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Contracts\SourceControlProvider;
use App\Sources\Context\SourceContextFacts;
use App\Sync\SystemIntegrationRuntime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enumerates every project and non-disabled repository the `azdo-repos`
 * Source Control provider's credential can see, and dispatches one
 * AnalyzeRepositoryJob per repository as a single job batch on the
 * dedicated `static-analysis` queue, tracked via a StaticAnalysisRun.
 *
 * Runs on the app's own default queue — it only makes Azure DevOps REST
 * API calls (the same cost profile as DispatchRepositoryCollectionRunsJob),
 * never the static-analysis queue reserved for the isolated
 * static-analysis-collector container's build/analyze work.
 *
 * Mirrors DispatchRepositoryCollectionRunsJob's shipped structure exactly
 * (including duplicating buildTargets() rather than sharing it — see that
 * class for the same reasoning); see that class's own docblock for why
 * completion is driven by each per-repository job's own row-locked
 * recordCompletion(), not Illuminate\Bus\Batch's then()/catch()/finally().
 */
final class DispatchStaticAnalysisRunsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    private const SOURCE_CONTROL_ID = 'azdo-repos';

    /**
     * Bypasses every per-repository skip for this sweep. The mitigation for
     * the one thing an analysed-commit comparison cannot see: a
     * static-analysis-collector image shipping new analysers or rules.
     */
    public function __construct(public readonly bool $force = false) {}

    public function uniqueId(): string
    {
        return 'static-analysis';
    }

    public function handle(SystemIntegrationRuntime $runtime): void
    {
        $run = StaticAnalysisRun::query()->create([
            'source_control_id' => self::SOURCE_CONTROL_ID,
            'started_at' => now(),
            'status' => 'running',
            'counts_json' => [],
        ]);

        $provider = $runtime->sourceControl(self::SOURCE_CONTROL_ID);

        if (! $provider instanceof EnumeratesInventory || ! $runtime->hasRequiredSystemCredentials($provider->credentialFields())) {
            $message = 'Azure DevOps Repos credential is not configured.';
            $context = ['run' => $run->id, 'operation' => 'discover'];

            $run->update([
                'status' => 'failure',
                'finished_at' => now(),
                'error_message' => $message,
            ]);

            // Not Log::error() (the default `stack` channel already includes
            // the `database` handler at level=error) — that would create a
            // second, poorer-context ErrorLog row for the same failure.
            Log::channel('single')->error($message, $context);

            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'static-analysis',
                'message' => $message,
                'context_json' => $context,
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

            $run->update([
                'counts_json' => [
                    'repositories_considered' => count($targets),
                    'repositories_completed' => 0,
                    'repositories_failed' => 0,
                    'repositories_skipped' => 0,
                ],
            ]);

            $batchName = 'static-analysis:' . $run->id;

            try {
                $batch = Bus::batch(array_map(
                    fn (RepositoryCollectionTarget $target): AnalyzeRepositoryJob => new AnalyzeRepositoryJob($target, $run->id, $this->force),
                    $targets,
                ))
                    ->name($batchName)
                    ->onQueue('static-analysis')
                    ->allowFailures()
                    ->dispatch();

                $run->update(['batch_id' => $batch->id]);
            } catch (Throwable $e) {
                // The `sync` queue connection re-throws after a job's own
                // failed() hook already ran (see AnalyzeRepositoryJob::
                // recordCompletion()) — if every repository was already
                // accounted for before this exception propagated here, the
                // run has already correctly finished; do not overwrite it.
                $run->refresh();

                if ($run->status === 'running') {
                    $context = ['run' => $run->id, 'operation' => 'dispatch'];

                    $run->update([
                        'status' => 'failure',
                        'finished_at' => now(),
                        'error_message' => $e->getMessage(),
                    ]);

                    // Not Log::error() — see the "credential not configured" branch's identical comment.
                    Log::channel('single')->error($e->getMessage(), $context);

                    ErrorLog::query()->create([
                        'level' => 'error',
                        'channel' => 'static-analysis',
                        'message' => $e->getMessage(),
                        'context_json' => $context,
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
                        'channel' => 'static-analysis',
                        ...app(AzDoOwnerLookup::class)->forAzDoRepository($project->sourceSystemId, $container->sourceContainerId),
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
