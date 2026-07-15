<?php

namespace App\Sync;

use App\Assets\AzDoProjectLinker;
use App\Assets\StaleRecordSweeper;
use App\Events\SyncRunFinished;
use App\Integrations\IntegrationSettingsRepository;
use App\Integrations\SystemIntegrationRuntime;
use App\Models\ErrorLog;
use App\Models\SyncRun;
use App\Sources\Contracts\EnrichesFetchedEvents;
use App\Sources\Contracts\QueuesEnrichmentJobs;
use App\Sources\Contracts\Source;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FetchSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 1800;

    public function __construct(public readonly string $sourceId) {}

    public function uniqueId(): string
    {
        return 'fetch-source:' . $this->sourceId;
    }

    public function failed(Throwable $exception): void
    {
        $message = $this->syncErrorMessage($exception);

        $run = SyncRun::query()
            ->where('source_id', $this->sourceId)
            ->where('status', 'running')
            ->latest('id')
            ->first();

        if ($run === null) {
            return;
        }

        $run->update([
            'finished_at' => now(),
            'status' => 'failure',
            'error_message' => $message,
        ]);

        app(IntegrationSettingsRepository::class)->markSyncResult('source', $this->sourceId, false, $message);

        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'sync',
            'message' => $message,
            'context_json' => [
                'source_id' => $this->sourceId,
                'path' => 'failed',
            ],
            'trace' => $exception->getTraceAsString(),
            'occurred_at' => now(),
        ]);

        event(new SyncRunFinished($run));
    }

    public function handle(
        SystemIntegrationRuntime $runtime,
        Upserter $upserter,
        ?IntegrationSettingsRepository $settings = null,
        ?SystemContainerUpserter $systemContainerUpserter = null,
        ?AzDoProjectLinker $azDoProjectLinker = null,
        ?StaleRecordSweeper $sweeper = null,
    ): void {
        $settings ??= app(IntegrationSettingsRepository::class);
        $systemContainerUpserter ??= app(SystemContainerUpserter::class);
        $azDoProjectLinker ??= app(AzDoProjectLinker::class);
        $sweeper ??= app(StaleRecordSweeper::class);

        $run = SyncRun::query()->create([
            'source_id' => $this->sourceId,
            'started_at' => now(),
            'status' => 'running',
            'counts_json' => [],
        ]);

        $counts = [
            'systems_created' => 0,
            'systems_updated' => 0,
            'containers_created' => 0,
            'containers_updated' => 0,
            'events_created' => 0,
            'events_updated' => 0,
        ];

        try {
            $since = SyncRun::query()
                ->where('source_id', $this->sourceId)
                ->where('status', 'success')
                ->whereNotNull('finished_at')
                ->orderByDesc('finished_at')
                ->value('finished_at');

            $sinceCarbon = $since !== null ? Carbon::parse((string) $since) : null;

            $runtime->runSource($this->sourceId, function (Source $source) use ($sinceCarbon, $upserter, $systemContainerUpserter, $azDoProjectLinker, $sweeper, &$counts): void {
                $systemIdMap = [];
                $containerIdMap = [];

                foreach ($source->fetchSystems() as $systemDto) {
                    ['system' => $system, 'wasCreated' => $isNew] = $systemContainerUpserter->upsertSystem($this->sourceId, $systemDto);
                    $azDoProjectLinker->linkSystemToAsset($system);

                    $systemIdMap[$systemDto->sourceSystemId] = $system->id;
                    $counts[$isNew ? 'systems_created' : 'systems_updated']++;

                    foreach ($source->fetchContainers($systemDto) as $containerDto) {
                        ['container' => $container, 'wasCreated' => $containerIsNew] = $systemContainerUpserter->upsertContainer($system, $containerDto);
                        $azDoProjectLinker->ensureRepositoryMapping($container);

                        $containerIdMap[$systemDto->sourceSystemId . ':' . $containerDto->sourceContainerId] = $container->id;
                        $containerIdMap[$containerDto->sourceContainerId] = $container->id;
                        $counts[$containerIsNew ? 'containers_created' : 'containers_updated']++;
                    }
                }

                // The systems/containers enumeration above just completed in full without
                // throwing, so it's safe to sweep now — independent of whether the events fetch
                // below succeeds, since that has no bearing on which systems/containers exist.
                $sweeper->sweepSystems($this->sourceId, array_values($systemIdMap));
                $sweeper->sweepContainers($this->sourceId, array_values(array_unique($containerIdMap)));

                foreach ($source->fetchEvents($sinceCarbon) as $eventDto) {
                    if ($source instanceof EnrichesFetchedEvents) {
                        $eventDto = $source->enrichFetchedEvent($eventDto);
                    }

                    $event = $upserter->upsert($this->sourceId, $eventDto, $systemIdMap, $containerIdMap);
                    $counts[$event->wasRecentlyCreated ? 'events_created' : 'events_updated']++;

                    if ($source instanceof QueuesEnrichmentJobs) {
                        $job = $source->enrichmentJobFor($this->sourceId, $event);
                        if ($job !== null) {
                            dispatch($job);
                        }
                    }
                }
            });

            $run->update([
                'finished_at' => now(),
                'status' => 'success',
                'counts_json' => $counts,
                'error_message' => null,
            ]);

            $settings->markSyncResult('source', $this->sourceId, true);

            event(new SyncRunFinished($run));
        } catch (Throwable $e) {
            $message = $this->syncErrorMessage($e);

            $run->update([
                'finished_at' => now(),
                'status' => 'failure',
                'counts_json' => $counts,
                'error_message' => $message,
            ]);

            $settings->markSyncResult('source', $this->sourceId, false, $message);

            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'sync',
                'message' => $message,
                'context_json' => [
                    'source_id' => $this->sourceId,
                    'path' => 'handle',
                ],
                'trace' => $e->getTraceAsString(),
                'occurred_at' => now(),
            ]);

            event(new SyncRunFinished($run));

            throw new RuntimeException($message);
        }
    }

    private function syncErrorMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Data too long for column')) {
            $column = Str::between($message, "Data too long for column '", "'");

            return $column !== ''
                ? "Source {$this->sourceId} sync failed: value too long for security_events.{$column}. Run migrations and retry the source fetch."
                : "Source {$this->sourceId} sync failed: an upstream value exceeded a database column size. Run migrations and retry the source fetch.";
        }

        return Str::limit(preg_replace('/\s+/', ' ', trim($message)) ?? trim($message), 1000);
    }
}
