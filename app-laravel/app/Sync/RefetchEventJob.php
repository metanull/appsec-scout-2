<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Models\SecurityEvent;
use App\Sources\Registry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class RefetchEventJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $eventId) {}

    public function handle(Registry $registry, Upserter $upserter, Recorder $recorder): void
    {
        $event = SecurityEvent::query()
            ->with(['softwareSystem', 'container'])
            ->findOrFail($this->eventId);

        $source = $registry->find($event->source_id);

        if ($source === null) {
            throw new \RuntimeException("Source {$event->source_id} is not enabled");
        }

        $dto = $source->fetchRawEvent($event);

        $systemIdMap = [
            $dto->sourceSystemId => $event->software_system_id,
        ];

        if (is_string($event->softwareSystem?->source_system_id) && $event->softwareSystem->source_system_id !== '') {
            $systemIdMap[$event->softwareSystem->source_system_id] = $event->software_system_id;
        }

        $containerIdMap = [];

        if ($event->container_id !== null) {
            $sourceContainerId = $dto->sourceContainerId;

            if (is_string($sourceContainerId) && $sourceContainerId !== '') {
                $containerIdMap[$dto->sourceSystemId . ':' . $sourceContainerId] = $event->container_id;
            } elseif (is_string($event->container?->source_container_id) && $event->container->source_container_id !== '') {
                $containerIdMap[(string) $event->softwareSystem?->source_system_id . ':' . $event->container->source_container_id] = $event->container_id;
            }
        }

        $upserter->upsert($event->source_id, $dto, $systemIdMap, $containerIdMap);

        $recorder->recordRefetch(SecurityEvent::class, (string) $event->id, [
            'source_id' => $event->source_id,
        ]);
    }
}
