<?php

declare(strict_types=1);

namespace App\Trackers;

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;

final class TrackerProjectLinker
{
    /**
     * Idempotently create tracker project links for the systems and containers of the given events.
     *
     * @param  list<SecurityEvent>  $events
     */
    public function learnFromEvents(
        array $events,
        string $trackerId,
        string $projectKey,
        ?string $projectName,
        ?int $userId,
    ): void {
        $systemIds = [];
        $containerIds = [];

        foreach ($events as $event) {
            $systemId = $event->getAttribute('software_system_id');

            if (is_int($systemId)) {
                $systemIds[] = $systemId;
            }

            $containerId = $event->getAttribute('container_id');

            if (is_int($containerId)) {
                $containerIds[] = $containerId;
            }
        }

        $systemIds = array_values(array_unique($systemIds));
        $containerIds = array_values(array_unique($containerIds));

        foreach ($systemIds as $systemId) {
            $this->upsert(SoftwareSystem::class, $systemId, $trackerId, $projectKey, $projectName, $userId);
        }

        foreach ($containerIds as $containerId) {
            $this->upsert(SecurityContainer::class, $containerId, $trackerId, $projectKey, $projectName, $userId);
        }
    }

    private function upsert(
        string $ownerType,
        int $ownerId,
        string $trackerId,
        string $projectKey,
        ?string $projectName,
        ?int $userId,
    ): void {
        $existing = TrackerProjectLink::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('tracker_id', $trackerId)
            ->where('project_key', $projectKey)
            ->first();

        if ($existing instanceof TrackerProjectLink) {
            if ($projectName !== null && $existing->project_name === null) {
                $existing->forceFill(['project_name' => $projectName])->save();
            }

            return;
        }

        TrackerProjectLink::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'tracker_id' => $trackerId,
            'project_key' => $projectKey,
            'project_name' => $projectName,
            'is_default' => false,
            'created_by_user_id' => $userId,
        ]);
    }
}
