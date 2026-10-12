<?php

namespace App\Mcp\Tools\Operations;

use App\Filament\Support\OperationsAuthorization;
use App\Mcp\Support\ErrorLogScope;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\ErrorLog;
use App\Models\RepositoryCollectionRun;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\Models\SyncRun;
use App\Models\User;
use App\Queue\QueueRuntimeInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('get-operations-status')]
#[Title('Get operations status')]
#[Description('Shows whether background work finished or failed: the latest fetch run per source, recent repository collection and static analysis runs (and whether one is running), error counts of the last 24 hours by channel, and the queue backlog. With an asset, system or container, runs and errors are limited to it and each system reports when it was last synced: a single-system fetch (fetch-alerts) records no run, so its synced_at moving and no new errors is how it shows as done. Use list-errors for the error details. Requires admin.queue or work-items.sync.')]
#[IsReadOnly]
final class GetOperationsStatusTool extends AppSecTool
{
    private const RECENT_RUNS = 5;

    public function __construct(private readonly QueueRuntimeInspector $queue) {}

    public function schema(JsonSchema $schema): array
    {
        return Scope::schema($schema);
    }

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        if (! OperationsAuthorization::canQueueOrSync()) {
            return Response::error('Permission denied: this tool requires the admin.queue or work-items.sync permission.');
        }

        $scope = Scope::fromRequest($request);
        $systems = $scope->isEmpty() ? null : $scope->systems();
        $systemIds = $systems?->map(fn (SoftwareSystem $system): int => $system->id)->all();

        return Response::structured(array_filter([
            'sources' => $this->sources($systems?->pluck('source_id')->unique()->values()->all()),
            'repository_collection' => $this->runs(RepositoryCollectionRun::class, $systemIds),
            'static_analysis' => $this->runs(StaticAnalysisRun::class, $systemIds),
            'systems' => $systems?->map(fn (SoftwareSystem $system): array => [
                'id' => $system->id,
                'name' => $system->name,
                'source_id' => $system->source_id,
                'synced_at' => Format::date($system->getAttribute('synced_at')),
                'errors_last_24h' => ErrorLog::query()->where('software_system_id', $system->id)->where('occurred_at', '>=', now()->subDay())->count(),
            ])->values()->all(),
            'errors_last_24h' => $this->errorCounts($scope),
            'queue' => $this->queueStatus(),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<int, mixed>|null  $sourceIds  null: every source with a run
     * @return array<int, array<string, mixed>>
     */
    private function sources(?array $sourceIds): array
    {
        $sourceIds ??= SyncRun::query()->distinct()->orderBy('source_id')->pluck('source_id')->all();
        $sourceIds = array_values(array_map(fn (mixed $id): string => (string) $id, $sourceIds));

        return array_map(function (string $sourceId): array {
            $latest = SyncRun::query()->where('source_id', $sourceId)->latest('started_at')->latest('id')->first();

            return [
                'source_id' => $sourceId,
                'running' => SyncRun::query()->where('source_id', $sourceId)->where('status', 'running')->exists(),
                'latest_run' => $latest === null ? null : self::runSummary($latest),
                'last_success_at' => Format::date(SyncRun::query()->where('source_id', $sourceId)->where('status', 'success')->latest('finished_at')->first()?->getAttribute('finished_at')),
            ];
        }, $sourceIds);
    }

    /**
     * @param  class-string<RepositoryCollectionRun|StaticAnalysisRun>  $model
     * @param  array<int, int>|null  $systemIds  null: every run
     * @return array{running: bool, recent: array<int, array<string, mixed>>}
     */
    private function runs(string $model, ?array $systemIds): array
    {
        $query = $model::query()
            ->with('softwareSystem:id,name')
            ->when($systemIds !== null, fn (Builder $query) => $query->where(function (Builder $inScope) use ($systemIds): void {
                $inScope->whereIn('software_system_id', $systemIds ?? [])->orWhereNull('software_system_id');
            }));

        return [
            'running' => (clone $query)->where('status', 'running')->exists(),
            'recent' => $query->latest('started_at')->latest('id')->limit(self::RECENT_RUNS)->get()
                ->map(fn (RepositoryCollectionRun|StaticAnalysisRun $run): array => [
                    'id' => $run->id,
                    'system' => FindingPresenter::named($run->softwareSystem),
                    ...self::runSummary($run),
                ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function runSummary(Model $run): array
    {
        return [
            'status' => $run->getAttribute('status'),
            'started_at' => Format::date($run->getAttribute('started_at')),
            'finished_at' => Format::date($run->getAttribute('finished_at')),
            'counts' => Format::arrayAttribute($run->getAttribute('counts_json')),
            'error_message' => $run->getAttribute('error_message'),
        ];
    }

    /** @return array<string, int> */
    private function errorCounts(Scope $scope): array
    {
        return ErrorLogScope::apply(ErrorLog::query(), $scope)
            ->where('occurred_at', '>=', now()->subDay())
            ->toBase()
            ->select('channel')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('channel')
            ->orderBy('channel')
            ->pluck('aggregate', 'channel')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array{pending: int|null, failed: int|null, error?: string} */
    private function queueStatus(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;

        try {
            return ['pending' => $this->queue->queuedCount(), 'failed' => $failed];
        } catch (Throwable $e) {
            return ['pending' => null, 'failed' => $failed, 'error' => 'The queue backend could not be read: ' . $e->getMessage()];
        }
    }
}
