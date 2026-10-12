<?php

namespace App\Sync;

use App\Sources\Contracts\EnrichesFetchedEvents;
use App\Sources\Contracts\QueuesEnrichmentJobs;
use App\Sources\Contracts\Source;
use App\Sources\Dto\EventDto;

/**
 * Upserts fetched events (enriching them first when the source supports it, and queueing any
 * per-event enrichment job it asks for). Shared by the whole-source fetch and the single-system
 * fetch.
 */
final class SourceEventIngestor
{
    public function __construct(private readonly Upserter $upserter) {}

    /**
     * @param  iterable<EventDto>  $events
     * @param  array<string, int>  $systemIdMap
     * @param  array<string, int>  $containerIdMap
     * @param  array<string, int>  $counts  incremented in place: events_created / events_updated
     */
    public function ingest(Source $source, string $sourceId, iterable $events, array $systemIdMap, array $containerIdMap, array &$counts): void
    {
        foreach ($events as $eventDto) {
            if ($source instanceof EnrichesFetchedEvents) {
                $eventDto = $source->enrichFetchedEvent($eventDto);
            }

            $event = $this->upserter->upsert($sourceId, $eventDto, $systemIdMap, $containerIdMap);
            $counts[$event->wasRecentlyCreated ? 'events_created' : 'events_updated']++;

            if ($source instanceof QueuesEnrichmentJobs) {
                $job = $source->enrichmentJobFor($sourceId, $event);

                if ($job !== null) {
                    dispatch($job);
                }
            }
        }
    }
}
