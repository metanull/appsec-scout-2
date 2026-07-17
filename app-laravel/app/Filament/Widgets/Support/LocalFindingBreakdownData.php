<?php

namespace App\Filament\Widgets\Support;

use App\Filament\Support\EventSeverityBadgeColor;
use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use Illuminate\Support\Facades\Cache;

/**
 * Cached aggregate rows for the Local Findings breakdown widgets, mirroring
 * AlertBreakdownData over the LocalFinding model (whose state column is
 * `status`).
 *
 * @phpstan-type BreakdownRow array{key: string, label: string, count: int, color: string|array<array-key, string>}
 */
final class LocalFindingBreakdownData
{
    private const CACHE_TTL_SECONDS = 300;

    public const STATE_CACHE_KEY = 'dashboard:breakdown:local-findings:state';

    public const WORK_ITEM_STATUS_CACHE_KEY = 'dashboard:breakdown:local-findings:work-item-status';

    public const SYSTEM_CACHE_KEY = 'dashboard:breakdown:local-findings:open-by-system';

    public const KIND_CACHE_KEY = 'dashboard:breakdown:local-findings:open-by-kind';

    public const SEVERITY_CACHE_KEY = 'dashboard:breakdown:local-findings:open-by-severity';

    /** @var list<string> */
    private const KNOWN_SEVERITIES = ['critical', 'high', 'medium', 'low', 'informational'];

    /**
     * @return list<BreakdownRow>
     */
    public static function stateBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::STATE_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $counts = LocalFinding::query()
                ->toBase()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $open = (int) ($counts[EventState::Open->value] ?? 0);
            $inProgress = (int) ($counts[EventState::InProgress->value] ?? 0);
            $closed = (int) ($counts[EventState::Acknowledged->value] ?? 0)
                + (int) ($counts[EventState::Resolved->value] ?? 0)
                + (int) ($counts[EventState::Dismissed->value] ?? 0);

            return [
                ['key' => EventState::Open->value, 'label' => 'Open', 'count' => $open, 'color' => EventStateBadgeColor::for(EventState::Open)],
                ['key' => EventState::InProgress->value, 'label' => 'In Progress', 'count' => $inProgress, 'color' => EventStateBadgeColor::for(EventState::InProgress)],
                ['key' => 'closed', 'label' => 'Closed', 'count' => $closed, 'color' => EventStateBadgeColor::for(EventState::Resolved)],
            ];
        });

        return $result;
    }

    /**
     * Mirror of AlertBreakdownData::workItemStatusBreakdown() over the
     * LocalFindingWorkItemLink table. Rows are not additive.
     *
     * @return list<BreakdownRow>
     */
    public static function workItemStatusBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::WORK_ITEM_STATUS_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $statusRows = LocalFindingWorkItemLink::query()
                ->toBase()
                ->selectRaw('work_item_state, COUNT(DISTINCT local_finding_id) as total')
                ->groupBy('work_item_state')
                ->orderByRaw('COUNT(DISTINCT local_finding_id) DESC')
                ->get();

            $noWorkItem = LocalFinding::query()->whereDoesntHave('workItemLinks')->count();

            return WorkItemStatusBreakdown::rows($statusRows, $noWorkItem);
        });

        return $result;
    }

    /**
     * Open local findings grouped by software system (top systems, then Others,
     * then Unassigned). Counts strictly status = open.
     *
     * @return list<BreakdownRow>
     */
    public static function openBySystemBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::SYSTEM_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $rows = LocalFinding::query()
                ->toBase()
                ->leftJoin('software_systems', 'local_findings.software_system_id', '=', 'software_systems.id')
                ->selectRaw('local_findings.software_system_id as system_id, software_systems.name as system_name, COUNT(*) as total')
                ->where('local_findings.status', EventState::Open->value)
                ->groupBy('local_findings.software_system_id', 'software_systems.name')
                ->orderByRaw('COUNT(*) DESC')
                ->get();

            return OpenBySystemBreakdown::rows($rows);
        });

        return $result;
    }

    /**
     * Open local findings grouped by kind, with zero buckets omitted. Colors
     * reuse the kind badge colors from LocalFindingResource. Counts strictly
     * status = open.
     *
     * @return list<BreakdownRow>
     */
    public static function openByKindBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::KIND_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $counts = LocalFinding::query()
                ->toBase()
                ->selectRaw('kind, COUNT(*) as total')
                ->where('status', EventState::Open->value)
                ->groupBy('kind')
                ->pluck('total', 'kind');

            $kinds = [
                LocalFinding::KIND_VULNERABILITY => 'warning',
                LocalFinding::KIND_SECRET => 'danger',
                LocalFinding::KIND_CODE_QUALITY => 'info',
            ];

            $rows = [];
            foreach ($kinds as $kind => $color) {
                $count = (int) ($counts[$kind] ?? 0);

                if ($count === 0) {
                    continue;
                }

                $rows[] = [
                    'key' => $kind,
                    'label' => str($kind)->replace('_', ' ')->title()->toString(),
                    'count' => $count,
                    'color' => $color,
                ];
            }

            return $rows;
        });

        return $result;
    }

    /**
     * Open local findings grouped by effective (override-aware) severity —
     * LOWER(COALESCE(overridden_severity, severity)), portable on MySQL 8 and
     * SQLite — critical → informational then Unknown for anything unmapped, zero
     * buckets omitted. Counts strictly status = open.
     *
     * @return list<BreakdownRow>
     */
    public static function openBySeverityBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::SEVERITY_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $grouped = LocalFinding::query()
                ->toBase()
                ->selectRaw('LOWER(COALESCE(overridden_severity, severity)) as effective, COUNT(*) as total')
                ->where('status', EventState::Open->value)
                ->groupByRaw('LOWER(COALESCE(overridden_severity, severity))')
                ->get();

            $buckets = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'informational' => 0, 'unknown' => 0];

            foreach ($grouped as $row) {
                $effective = $row->effective;
                $count = (int) $row->total;

                if ($effective !== null && in_array($effective, self::KNOWN_SEVERITIES, true)) {
                    $buckets[(string) $effective] += $count;
                } else {
                    $buckets['unknown'] += $count;
                }
            }

            $rows = [];
            foreach (EventSeverity::cases() as $severity) {
                if ($buckets[$severity->value] === 0) {
                    continue;
                }

                $rows[] = [
                    'key' => $severity->value,
                    'label' => ucfirst($severity->value),
                    'count' => $buckets[$severity->value],
                    'color' => EventSeverityBadgeColor::for($severity),
                ];
            }

            if ($buckets['unknown'] > 0) {
                $rows[] = ['key' => 'unknown', 'label' => 'Unknown', 'count' => $buckets['unknown'], 'color' => 'gray'];
            }

            return $rows;
        });

        return $result;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::STATE_CACHE_KEY);
        Cache::forget(self::WORK_ITEM_STATUS_CACHE_KEY);
        Cache::forget(self::SYSTEM_CACHE_KEY);
        Cache::forget(self::KIND_CACHE_KEY);
        Cache::forget(self::SEVERITY_CACHE_KEY);
    }
}
