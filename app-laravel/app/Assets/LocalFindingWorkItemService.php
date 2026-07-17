<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Integrations\OperatorIntegrationRuntime;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Support\MarkdownTruncation;
use App\Trackers\TrackerProjectLinker;
use Illuminate\Database\DatabaseManager;

final class LocalFindingWorkItemService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly Recorder $recorder,
        private readonly TrackerProjectLinker $linker,
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
    ): void {
        $findings = $this->findings($findingIds);

        $isGrouped = count($findings) > 1;
        $title = $isGrouped ? $this->buildGroupedTitle($findings) : $this->buildTitle($findings[0]);
        $description = $isGrouped ? $this->buildGroupedDescription($findings) : $this->buildDescription($findings[0]);

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

    /** @param  list<LocalFinding>  $findings */
    private function buildGroupedTitle(array $findings): string
    {
        $system = $this->systemName($findings[0]);
        $container = $this->containerName($findings[0]);

        $kinds = array_values(array_unique(array_map(fn (LocalFinding $finding): string => $this->kindLabel($finding->kind), $findings)));
        sort($kinds);

        $kindSummary = count($kinds) <= 2
            ? implode(', ', $kinds)
            : sprintf('%s + %d more', $kinds[0], count($kinds) - 1);

        $parts = [$system];

        if ($container !== null && $container !== $system) {
            $parts[] = $container;
        }

        $fileCount = $this->countUniqueFiles($findings);

        $parts[] = sprintf(
            '%s (%d finding%s, %d file%s)',
            $kindSummary,
            count($findings),
            count($findings) === 1 ? '' : 's',
            $fileCount,
            $fileCount === 1 ? '' : 's',
        );

        return implode(': ', $parts);
    }

    /** @param  list<LocalFinding>  $findings */
    private function buildGroupedDescription(array $findings): string
    {
        $sections = [
            sprintf('## %s', $this->buildGroupedTitle($findings)),
            $this->buildSeverityTable($findings),
        ];

        foreach ($this->groupByKind($findings) as $group) {
            $sections[] = sprintf('## %s', $this->kindLabel($group['kind']));
            $description = $this->firstDescription($group['findings']);

            if ($description !== null) {
                $sections[] = "### Description\n\n{$description}";
            }

            $sections[] = $this->buildOccurrences($group['findings']);
        }

        return MarkdownTruncation::atParagraphBoundary(implode("\n\n", array_filter($sections)));
    }

    /** @param  list<LocalFinding>  $findings */
    private function buildSeverityTable(array $findings): string
    {
        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, int> $weights */
        $weights = [];

        foreach ($findings as $finding) {
            $label = $finding->effectiveSeverityLabel();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
            $weights[$label] = $this->severityWeight($finding);
        }

        uksort($counts, fn (string $left, string $right): int => ($weights[$right] <=> $weights[$left]) ?: strcmp($left, $right));

        $lines = ['| Severity | Count |', '| --- | ---: |'];

        foreach ($counts as $label => $count) {
            $lines[] = sprintf('| %s | %d |', $label, $count);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<LocalFinding>  $findings
     * @return list<array{kind: string, findings: list<LocalFinding>}>
     */
    private function groupByKind(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $groups[$finding->kind][] = $finding;
        }

        uasort($groups, fn (array $left, array $right): int => $this->highestSeverityWeight($right) <=> $this->highestSeverityWeight($left));

        $resolved = [];

        foreach ($groups as $kind => $groupFindings) {
            $resolved[] = ['kind' => (string) $kind, 'findings' => $groupFindings];
        }

        return $resolved;
    }

    /** @param  list<LocalFinding>  $findings */
    private function buildOccurrences(array $findings): string
    {
        $lines = ['### Occurrences'];

        foreach ($findings as $finding) {
            $lines[] = $this->buildOccurrenceLine($finding);
        }

        return implode("\n", $lines);
    }

    private function buildOccurrenceLine(LocalFinding $finding): string
    {
        if ($finding->file_path !== '') {
            $location = $finding->start_line !== null
                ? "{$finding->file_path}:{$finding->start_line}"
                : $finding->file_path;
        } elseif ($finding->package_name !== null && $finding->package_name !== '') {
            $location = trim("{$finding->package_name} {$finding->package_version}");
        } else {
            $location = $finding->title;
        }

        $line = '- ' . $location;

        if ($finding->rule_id !== '') {
            $line .= sprintf(' (%s)', $finding->rule_id);
        }

        return $line;
    }

    /** @param  list<LocalFinding>  $findings */
    private function firstDescription(array $findings): ?string
    {
        foreach ($findings as $finding) {
            if ($finding->description !== null && trim($finding->description) !== '') {
                return $finding->description;
            }
        }

        return null;
    }

    private function systemName(LocalFinding $finding): string
    {
        $system = $finding->getRelationValue('softwareSystem');

        return $system instanceof SoftwareSystem ? $system->name : 'Security';
    }

    private function containerName(LocalFinding $finding): ?string
    {
        $owner = $finding->getRelationValue('owner');

        return $owner instanceof SecurityContainer ? $owner->name : null;
    }

    /** @param  list<LocalFinding>  $findings */
    private function countUniqueFiles(array $findings): int
    {
        $paths = array_unique(array_filter(array_map(
            fn (LocalFinding $finding): ?string => $finding->file_path !== '' ? $finding->file_path : null,
            $findings,
        )));

        return max(count($paths), 1);
    }

    /** @param  list<LocalFinding>  $findings */
    private function highestSeverityWeight(array $findings): int
    {
        if ($findings === []) {
            return 1;
        }

        return max(array_map(fn (LocalFinding $finding): int => $this->severityWeight($finding), $findings));
    }

    private function severityWeight(LocalFinding $finding): int
    {
        return match (strtoupper($finding->effectiveSeverityLabel())) {
            'CRITICAL' => 5,
            'HIGH' => 4,
            'MEDIUM' => 3,
            'LOW' => 2,
            default => 1,
        };
    }

    private function kindLabel(string $kind): string
    {
        return str_replace('_', ' ', ucfirst($kind));
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
