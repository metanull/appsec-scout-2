<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\TrackerProjectLinker;
use App\Triage\OperatorIntegrationRuntime;
use Illuminate\Database\DatabaseManager;

final class LocalFindingWorkItemService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly Recorder $recorder,
        private readonly TrackerProjectLinker $linker,
        private readonly LocalFindingWorkItemDescriptionBuilder $descriptions,
    ) {}

    /**
     * @param  list<int>  $findingIds
     * @param  list<string>  $labels
     */
    public function createForFindings(
        array $findingIds,
        int $userId,
        string $trackerId,
        string $projectKey,
        string $itemType,
        array $labels = [],
        ?string $priority = null,
        ?string $assigneeId = null,
        ?string $parentId = null,
    ): WorkItemDto {
        $findings = $this->findings($findingIds);

        $isGrouped = count($findings) > 1;
        $title = $isGrouped ? $this->descriptions->buildGroupedTitle($findings) : $this->descriptions->buildTitle($findings[0]);
        $description = $isGrouped ? $this->descriptions->buildGroupedDescription($findings) : $this->descriptions->buildDescription($findings[0]);

        /** @var WorkItemDto $workItem */
        $workItem = $this->runtime->runTracker($trackerId, $userId, fn (Tracker $tracker) => $tracker->createWorkItem(new CreateWorkItemRequest(
            projectKey: $projectKey,
            itemType: $itemType,
            title: $title,
            description: $description,
            labels: $this->normalizeLabels($labels),
            priority: $priority,
            assigneeId: $assigneeId,
            parentId: $parentId,
        )));

        $this->db->transaction(function () use ($findings, $workItem, $trackerId, $projectKey, $userId, $isGrouped): void {
            foreach ($findings as $finding) {
                LocalFindingWorkItemLink::query()->create([
                    'local_finding_id' => $finding->id,
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

            $this->linker->learnFromFindings($findings, $trackerId, $projectKey, null, $userId);

            $this->recorder->recordWorkItemCreated(LocalFinding::class, (string) $findings[0]->id, [
                'tracker_id' => $trackerId,
                'work_item_id' => $workItem->id,
                'project_key' => $projectKey,
                'finding_ids' => array_map(fn (LocalFinding $finding): int => $finding->id, $findings),
                'grouped' => $isGrouped,
            ]);
        });

        return $workItem;
    }

    /** @param  list<int>  $findingIds */
    public function linkExisting(array $findingIds, int $userId, string $trackerId, string $workItemId, string $projectKey = ''): void
    {
        $findings = $this->findings($findingIds);
        $workItem = $this->runtime->runTracker($trackerId, $userId, fn (Tracker $tracker) => $tracker->getWorkItem($workItemId))
            ?? throw new \RuntimeException('Selected work item could not be loaded from the tracker.');

        $resolvedProjectKey = $projectKey !== '' ? $projectKey : $workItem->projectKey;

        $duplicate = LocalFindingWorkItemLink::query()
            ->whereIn('local_finding_id', array_map(fn (LocalFinding $finding): int => $finding->id, $findings))
            ->where('tracker_id', $trackerId)
            ->where('work_item_id', $workItemId)
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException('One or more selected findings are already linked to this work item.');
        }

        $this->db->transaction(function () use ($findings, $workItem, $trackerId, $resolvedProjectKey, $userId): void {
            foreach ($findings as $finding) {
                LocalFindingWorkItemLink::query()->create([
                    'local_finding_id' => $finding->id,
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

            $this->linker->learnFromFindings($findings, $trackerId, $resolvedProjectKey, null, $userId);

            $this->recorder->recordWorkItemLinked(LocalFinding::class, (string) $findings[0]->id, [
                'tracker_id' => $trackerId,
                'work_item_id' => $workItem->id,
                'project_key' => $resolvedProjectKey,
                'finding_ids' => array_map(fn (LocalFinding $finding): int => $finding->id, $findings),
            ]);
        });
    }

    public function unlink(LocalFindingWorkItemLink $link): void
    {
        $subjectId = (string) $link->local_finding_id;

        $link->delete();

        $this->recorder->recordWorkItemUnlinked(LocalFinding::class, $subjectId, [
            'tracker_id' => $link->tracker_id,
            'work_item_id' => $link->work_item_id,
        ]);
    }

    /**
     * @param  list<int>  $findingIds
     * @return list<LocalFinding>
     */
    private function findings(array $findingIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $findingIds)));
        $findings = LocalFinding::query()
            ->with(['softwareSystem', 'owner'])
            ->whereKey($ids)
            ->get()
            ->values();

        if ($findings->count() !== count($ids) || $findings->isEmpty()) {
            throw new \RuntimeException('One or more selected findings could not be loaded.');
        }

        /** @var list<LocalFinding> $resolved */
        $resolved = $findings->all();

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
