<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Integrations\OperatorIntegrationRuntime;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use Illuminate\Database\DatabaseManager;

final class LocalFindingWorkItemService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly Recorder $recorder,
    ) {}

    /** @param list<string> $labels */
    public function createForFinding(
        LocalFinding $finding,
        int $userId,
        string $trackerId,
        string $projectKey,
        string $itemType,
        array $labels = [],
        ?string $priority = null,
        ?string $assigneeId = null,
        ?string $parentId = null,
    ): void {
        $title = $this->buildTitle($finding);
        $description = $this->buildDescription($finding);

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

        $this->db->transaction(function () use ($finding, $workItem, $trackerId, $projectKey, $userId): void {
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

            $this->recorder->recordWorkItemCreated(LocalFinding::class, (string) $finding->id, [
                'tracker_id' => $trackerId,
                'work_item_id' => $workItem->id,
                'project_key' => $projectKey,
            ]);
        });
    }

    public function linkExisting(LocalFinding $finding, int $userId, string $trackerId, string $workItemId, string $projectKey = ''): void
    {
        $workItem = $this->runtime->runTracker($trackerId, $userId, fn (Tracker $tracker) => $tracker->getWorkItem($workItemId))
            ?? throw new \RuntimeException('Selected work item could not be loaded from the tracker.');

        $resolvedProjectKey = $projectKey !== '' ? $projectKey : $workItem->projectKey;

        $duplicate = LocalFindingWorkItemLink::query()
            ->where('local_finding_id', $finding->id)
            ->where('tracker_id', $trackerId)
            ->where('work_item_id', $workItemId)
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException('This finding is already linked to this work item.');
        }

        $this->db->transaction(function () use ($finding, $workItem, $trackerId, $resolvedProjectKey, $userId): void {
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

            $this->recorder->recordWorkItemLinked(LocalFinding::class, (string) $finding->id, [
                'tracker_id' => $trackerId,
                'work_item_id' => $workItem->id,
                'project_key' => $resolvedProjectKey,
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

    private function buildTitle(LocalFinding $finding): string
    {
        $location = $finding->start_line !== null
            ? "{$finding->file_path}:{$finding->start_line}"
            : $finding->file_path;

        return sprintf('%s: %s (%s)', $finding->kind, $finding->title, $location);
    }

    private function buildDescription(LocalFinding $finding): string
    {
        $lines = [
            sprintf('## %s', $finding->title),
            sprintf('- Kind: %s', $finding->kind),
            sprintf('- Severity: %s', $finding->effectiveSeverityLabel()),
            sprintf('- Location: %s', $finding->start_line !== null ? "{$finding->file_path}:{$finding->start_line}" : $finding->file_path),
        ];

        if ($finding->rule_id !== '') {
            $lines[] = sprintf('- Rule: %s', $finding->rule_id);
        }

        if ($finding->package_name !== null) {
            $lines[] = sprintf('- Package: %s %s', $finding->package_name, $finding->package_version);
        }

        if ($finding->description !== null && trim($finding->description) !== '') {
            $lines[] = '';
            $lines[] = '### Description';
            $lines[] = '';
            $lines[] = $finding->description;
        }

        return implode("\n", $lines);
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
