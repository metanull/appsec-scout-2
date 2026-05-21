<?php

namespace App\Trackers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class CreateWorkItemJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @param  list<int>  $eventIds
     * @param  list<string>  $labels
     */
    public function __construct(
        public readonly array $eventIds,
        public readonly int $userId,
        public readonly string $trackerId,
        public readonly string $projectKey,
        public readonly string $itemType,
        public readonly array $labels = [],
        public readonly ?string $priority = null,
        public readonly ?string $assigneeId = null,
        public readonly ?string $parentId = null,
    ) {}

    public function handle(WorkItemService $service): void
    {
        $service->createForEvents(
            eventIds: $this->eventIds,
            userId: $this->userId,
            trackerId: $this->trackerId,
            projectKey: $this->projectKey,
            itemType: $this->itemType,
            labels: $this->labels,
            priority: $this->priority,
            assigneeId: $this->assigneeId,
            parentId: $this->parentId,
        );
    }
}
