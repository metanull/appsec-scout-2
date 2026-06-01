<?php

namespace App\Trackers;

use App\Audit\Recorder;
use App\Integrations\OperatorIntegrationRuntime;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use Illuminate\Database\DatabaseManager;

final class WorkItemService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DescriptionBuilder $descriptionBuilder,
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly Recorder $recorder,
        private readonly TrackerProjectLinker $linker,
    ) {}

    /**
     * @param  list<int>  $eventIds
     * @param  list<string>  $labels
     */
    public function createForEvents(
        array $eventIds,
        int $userId,
        string $trackerId,
        string $projectKey,
        string $itemType,
        array $labels = [],
        ?string $priority = null,
        ?string $assigneeId = null,
        ?string $parentId = null,
    ): void {
        $events = $this->events($eventIds);

        $isGrouped = count($events) > 1;
        $title = $isGrouped
            ? $this->descriptionBuilder->buildGroupedTitle($events)
            : $this->descriptionBuilder->buildTitle($events[0]);
        $description = $isGrouped
            ? $this->descriptionBuilder->buildGrouped($events)
            : $this->descriptionBuilder->buildSingle($events[0]);

        $workItem = $this->runtime->runTracker($trackerId, $userId, function (Tracker $tracker) use ($projectKey, $itemType, $title, $description, $labels, $priority, $assigneeId, $parentId) {
            return $tracker->createWorkItem(new CreateWorkItemRequest(
                projectKey: $projectKey,
                itemType: $itemType,
                title: $title,
                description: $description,
                labels: $this->normalizeLabels($labels),
                priority: $priority,
                assigneeId: $assigneeId,
                parentId: $parentId,
            ));
        });

        $this->db->transaction(function () use ($events, $workItem, $trackerId, $projectKey, $userId, $isGrouped): void {
            foreach ($events as $event) {
                WorkItemLink::query()->create([
                    'event_id' => $event->id,
                    'tracker_id' => $trackerId,
                    'work_item_id' => $workItem->id,
                    'work_item_url' => $workItem->url,
                    'work_item_title' => $workItem->title,
                    'work_item_state' => $workItem->state,
                    'created_by_user_id' => $userId,
                    'created_at' => now(),
                    'synced_at' => now(),
                ]);
            }

            $this->linker->learnFromEvents($events, $trackerId, $projectKey, null, $userId);

            $this->recorder->recordWorkItemCreated(SecurityEvent::class, (string) $events[0]->id, [
                'tracker_id' => $trackerId,
                'work_item_id' => $workItem->id,
                'project_key' => $projectKey,
                'event_ids' => array_map(fn (SecurityEvent $event): int => $event->id, $events),
                'grouped' => $isGrouped,
            ]);
        });
    }

    /** @param  list<int>  $eventIds */
    public function linkExisting(array $eventIds, int $userId, string $trackerId, string $workItemId, string $projectKey = ''): void
    {
        $events = $this->events($eventIds);
        $workItem = $this->runtime->runTracker($trackerId, $userId, fn (Tracker $tracker) => $tracker->getWorkItem($workItemId))
            ?? throw new \RuntimeException('Selected work item could not be loaded from the tracker.');

        $resolvedProjectKey = $projectKey !== '' ? $projectKey : $workItem->projectKey;

        $duplicate = WorkItemLink::query()
            ->whereIn('event_id', array_map(fn (SecurityEvent $event): int => $event->id, $events))
            ->where('tracker_id', $trackerId)
            ->where('work_item_id', $workItemId)
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException('One or more selected alerts are already linked to this work item.');
        }

        $this->db->transaction(function () use ($events, $workItem, $trackerId, $resolvedProjectKey, $userId): void {
            foreach ($events as $event) {
                WorkItemLink::query()->create([
                    'event_id' => $event->id,
                    'tracker_id' => $trackerId,
                    'work_item_id' => $workItem->id,
                    'work_item_url' => $workItem->url,
                    'work_item_title' => $workItem->title,
                    'work_item_state' => $workItem->state,
                    'created_by_user_id' => $userId,
                    'created_at' => now(),
                    'synced_at' => now(),
                ]);
            }

            $this->linker->learnFromEvents($events, $trackerId, $resolvedProjectKey, null, $userId);

            $this->recorder->recordWorkItemLinked(SecurityEvent::class, (string) $events[0]->id, [
                'tracker_id' => $trackerId,
                'work_item_id' => $workItem->id,
                'project_key' => $resolvedProjectKey,
                'event_ids' => array_map(fn (SecurityEvent $event): int => $event->id, $events),
            ]);
        });
    }

    public function unlink(WorkItemLink $link): void
    {
        $subjectId = (string) $link->event_id;

        $link->delete();

        $this->recorder->recordWorkItemUnlinked(SecurityEvent::class, $subjectId, [
            'tracker_id' => $link->tracker_id,
            'work_item_id' => $link->work_item_id,
        ]);
    }

    /**
     * @param  list<int>  $eventIds
     * @return list<SecurityEvent>
     */
    private function events(array $eventIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $eventIds)));
        $events = SecurityEvent::query()
            ->with(['softwareSystem', 'container'])
            ->whereKey($ids)
            ->get()
            ->values();

        if ($events->count() !== count($ids) || $events->isEmpty()) {
            throw new \RuntimeException('One or more selected alerts could not be loaded.');
        }

        /** @var list<SecurityEvent> $resolved */
        $resolved = $events->all();

        return $resolved;
    }

    /**
     * @param  array<int, mixed>  $labels
     * @return list<string>
     */
    private function normalizeLabels(array $labels): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $label): ?string => is_string($label) && trim($label) !== '' ? trim($label) : null,
            $labels,
        ))));
    }
}
