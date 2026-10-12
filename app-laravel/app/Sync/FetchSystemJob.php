<?php

namespace App\Sync;

use App\Models\ErrorLog;
use App\Models\SoftwareSystem;
use App\Sources\Contracts\Source;
use App\Sources\Dto\SystemDto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches the containers and alerts of one software system from its source, with the system
 * credential (a system-triggered operation, like FetchSourceJob).
 *
 * Deliberately narrower than FetchSourceJob: it records no SyncRun (that is the whole-pipeline
 * progress record, and the source's incremental `since` cursor is derived from it), always
 * fetches the system in full instead of incrementally, and never sweeps stale systems or
 * containers — it has only seen one system, so it cannot tell what disappeared elsewhere.
 */
final class FetchSystemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 1800;

    public function __construct(public readonly int $softwareSystemId) {}

    public function uniqueId(): string
    {
        return 'fetch-system:' . $this->softwareSystemId;
    }

    public function handle(SystemIntegrationRuntime $runtime, SourceContainerIngestor $containers, SourceEventIngestor $events): void
    {
        $counts = ['containers_created' => 0, 'containers_updated' => 0, 'events_created' => 0, 'events_updated' => 0];
        $system = SoftwareSystem::query()->find($this->softwareSystemId);

        try {
            if ($system === null) {
                throw new RuntimeException("Software system #{$this->softwareSystemId} no longer exists.");
            }

            if ($system->getAttribute('removed_at') !== null) {
                throw new RuntimeException("Software system #{$system->id} ({$system->name}) is no longer reported by {$system->source_id}; fetch the whole source instead.");
            }

            $metadata = $system->getAttribute('metadata');
            $systemDto = new SystemDto(
                sourceSystemId: $system->source_system_id,
                name: $system->name,
                description: $system->description,
                url: $system->url,
                metadata: is_array($metadata) ? $metadata : null,
            );

            $runtime->runSource($system->source_id, function (Source $source) use ($system, $systemDto, $containers, $events, &$counts): void {
                $containerIdMap = $containers->ingest($source, $system, $systemDto, $counts);

                $events->ingest(
                    $source,
                    $system->source_id,
                    $source->fetchEvents(null, $systemDto),
                    [$system->source_system_id => $system->id],
                    $containerIdMap,
                    $counts,
                );
            });

            Log::info('Software system fetched from its source.', [
                'software_system_id' => $system->id,
                'source_id' => $system->source_id,
                ...$counts,
            ]);
        } catch (Throwable $e) {
            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'sync',
                'message' => "Fetching software system #{$this->softwareSystemId} failed: {$e->getMessage()}",
                'context_json' => [
                    'software_system_id' => $this->softwareSystemId,
                    'source_id' => $system?->source_id,
                    'path' => 'handle',
                    'counts' => $counts,
                ],
                'trace' => $e->getTraceAsString(),
                'occurred_at' => now(),
            ]);

            throw $e;
        }
    }
}
