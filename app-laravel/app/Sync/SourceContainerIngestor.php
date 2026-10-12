<?php

namespace App\Sync;

use App\Assets\AzDoProjectLinker;
use App\Models\SoftwareSystem;
use App\Sources\Contracts\Source;
use App\Sources\Dto\SystemDto;

/**
 * Upserts the containers a source reports for one system and maps their source ids to local
 * ids, in the two key shapes Upserter looks events up by. Shared by the whole-source fetch and
 * the single-system fetch so both converge on the same rows.
 */
final class SourceContainerIngestor
{
    public function __construct(
        private readonly SystemContainerUpserter $upserter,
        private readonly AzDoProjectLinker $azDoProjectLinker,
    ) {}

    /**
     * @param  array<string, int>  $counts  incremented in place: containers_created / containers_updated
     * @return array<string, int> "{sourceSystemId}:{sourceContainerId}" and "{sourceContainerId}" => container id
     */
    public function ingest(Source $source, SoftwareSystem $system, SystemDto $systemDto, array &$counts): array
    {
        $containerIdMap = [];

        foreach ($source->fetchContainers($systemDto) as $containerDto) {
            ['container' => $container, 'wasCreated' => $isNew] = $this->upserter->upsertContainer($system, $containerDto);
            $this->azDoProjectLinker->ensureRepositoryMapping($container);

            $containerIdMap[$systemDto->sourceSystemId . ':' . $containerDto->sourceContainerId] = $container->id;
            $containerIdMap[$containerDto->sourceContainerId] = $container->id;
            $counts[$isNew ? 'containers_created' : 'containers_updated']++;
        }

        return $containerIdMap;
    }
}
