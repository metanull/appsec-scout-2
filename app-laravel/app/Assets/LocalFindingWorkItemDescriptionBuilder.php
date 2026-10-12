<?php

namespace App\Assets;

use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Trackers\Support\MarkdownTruncation;

/**
 * Title and Markdown description of a tracker work item created for one local finding, or for a
 * group of them. Shared by LocalFindingWorkItemService and the MCP dry run, so a preview shows
 * exactly what the tracker will receive.
 */
final class LocalFindingWorkItemDescriptionBuilder
{
    public function buildTitle(LocalFinding $finding): string
    {
        $location = $finding->start_line !== null
            ? "{$finding->file_path}:{$finding->start_line}"
            : $finding->file_path;

        return sprintf('%s: %s (%s)', $finding->kind, $finding->title, $location);
    }

    public function buildDescription(LocalFinding $finding): string
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
    public function buildGroupedTitle(array $findings): string
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
    public function buildGroupedDescription(array $findings): string
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
}
