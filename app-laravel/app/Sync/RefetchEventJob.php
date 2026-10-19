<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Credentials\RunWithSystemCredentials;
use App\Models\SecurityEvent;
use App\Sources\Registry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class RefetchEventJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $eventId) {}

    /**
     * The source reads its credential through the ambient owner scope; this declares it is the
     * system one, whoever queued the refetch.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RunWithSystemCredentials];
    }

    public function handle(Registry $registry, Upserter $upserter, Recorder $recorder): void
    {
        $event = SecurityEvent::query()
            ->with(['softwareSystem', 'container.sourceSoftwareSystem'])
            ->findOrFail($this->eventId);

        $source = $registry->find($event->source_id);

        if ($source === null) {
            throw new \RuntimeException("Source {$event->source_id} is not enabled");
        }

        $dto = $source->fetchRawEvent($event);

        // Map the source's own system id: when the alert's container was re-linked, the source
        // still reports it under the original system (Upserter then re-applies the link).
        $upstreamSystem = $event->upstreamSoftwareSystem();
        $upstreamSystemId = $upstreamSystem !== null ? $upstreamSystem->id : $event->software_system_id;

        $systemIdMap = [
            $dto->sourceSystemId => $upstreamSystemId,
        ];

        if (is_string($upstreamSystem?->source_system_id) && $upstreamSystem->source_system_id !== '') {
            $systemIdMap[$upstreamSystem->source_system_id] = $upstreamSystemId;
        }

        $containerIdMap = [];

        if ($event->container_id !== null) {
            $sourceContainerId = $dto->sourceContainerId;

            if (is_string($sourceContainerId) && $sourceContainerId !== '') {
                $containerIdMap[$dto->sourceSystemId . ':' . $sourceContainerId] = $event->container_id;
            } elseif (is_string($event->container?->source_container_id) && $event->container->source_container_id !== '') {
                $containerIdMap[(string) $upstreamSystem?->source_system_id . ':' . $event->container->source_container_id] = $event->container_id;
            }
        }

        $upserter->upsert($event->source_id, $dto, $systemIdMap, $containerIdMap);

        $recorder->recordRefetch(SecurityEvent::class, (string) $event->id, [
            'source_id' => $event->source_id,
        ]);
    }
}
