<?php

namespace App\Sync;

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Sources\Registry;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class FetchSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $sourceId) {}

    public function uniqueId(): string
    {
        return 'fetch-source:' . $this->sourceId;
    }

    public function handle(Registry $registry, Upserter $upserter): void
    {
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
            $source = $registry->find($this->sourceId);

            if ($source === null) {
                throw new \RuntimeException("Source {$this->sourceId} is not enabled");
            }

            $since = SyncRun::query()
                ->where('source_id', $this->sourceId)
                ->where('status', 'success')
                ->whereNotNull('finished_at')
                ->orderByDesc('finished_at')
                ->value('finished_at');

            $sinceCarbon = $since !== null ? Carbon::parse((string) $since) : null;

            $systemIdMap = [];
            $containerIdMap = [];

            foreach ($source->fetchSystems() as $systemDto) {
                $system = SoftwareSystem::query()->firstOrNew([
                    'source_id' => $this->sourceId,
                    'source_system_id' => $systemDto->sourceSystemId,
                ]);

                $isNew = ! $system->exists;

                $system->fill([
                    'name' => $systemDto->name,
                    'description' => $systemDto->description,
                    'url' => $systemDto->url,
                    'metadata' => $systemDto->metadata,
                    'first_seen_at' => $system->first_seen_at ?? now(),
                    'last_seen_at' => now(),
                    'synced_at' => now(),
                ]);
                $system->save();

                $systemIdMap[$systemDto->sourceSystemId] = $system->id;
                $counts[$isNew ? 'systems_created' : 'systems_updated']++;

                foreach ($source->fetchContainers($systemDto) as $containerDto) {
                    $container = SecurityContainer::query()->firstOrNew([
                        'software_system_id' => $system->id,
                        'source_container_id' => $containerDto->sourceContainerId,
                    ]);

                    $containerIsNew = ! $container->exists;

                    $container->fill([
                        'name' => $containerDto->name,
                        'kind' => $containerDto->kind,
                        'url' => $containerDto->url,
                        'metadata' => $containerDto->metadata,
                        'first_seen_at' => $container->first_seen_at ?? now(),
                        'last_seen_at' => now(),
                        'synced_at' => now(),
                    ]);
                    $container->save();

                    $containerIdMap[$systemDto->sourceSystemId . ':' . $containerDto->sourceContainerId] = $container->id;
                    $counts[$containerIsNew ? 'containers_created' : 'containers_updated']++;
                }
            }

            foreach ($source->fetchEvents($sinceCarbon) as $eventDto) {
                $created = $upserter->upsert($this->sourceId, $eventDto, $systemIdMap, $containerIdMap);
                $counts[$created ? 'events_created' : 'events_updated']++;
            }

            $run->update([
                'finished_at' => now(),
                'status' => 'success',
                'counts_json' => $counts,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'status' => 'failure',
                'counts_json' => $counts,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
