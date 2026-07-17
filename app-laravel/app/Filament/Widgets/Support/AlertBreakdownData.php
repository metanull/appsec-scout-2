<?php

namespace App\Filament\Widgets\Support;

use App\Filament\Support\EventSeverityBadgeColor;
use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Cache;

/**
 * Cached aggregate rows for the Alerts breakdown widgets. Each method returns
 * the shared breakdown row shape and caches under a dedicated key, flushed
 * together by flushCache() (wired into BustDashboardCache).
 *
 * @phpstan-type BreakdownRow array{key: string, label: string, count: int, color: string|array<array-key, string>}
 */
final class AlertBreakdownData
{
    private const CACHE_TTL_SECONDS = 300;

    public const STATE_CACHE_KEY = 'dashboard:breakdown:alerts:state';

    public const WORK_ITEM_STATUS_CACHE_KEY = 'dashboard:breakdown:alerts:work-item-status';

    public const SYSTEM_CACHE_KEY = 'dashboard:breakdown:alerts:open-by-system';

    public const SOURCE_CACHE_KEY = 'dashboard:breakdown:alerts:open-by-source';

    public const TYPE_CACHE_KEY = 'dashboard:breakdown:alerts:open-by-type';

    public const SEVERITY_CACHE_KEY = 'dashboard:breakdown:alerts:open-by-severity';

    /**
     * @return list<BreakdownRow>
     */
    public static function stateBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::STATE_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $counts = SecurityEvent::query()
                ->toBase()
                ->selectRaw('state, COUNT(*) as total')
                ->groupBy('state')
                ->pluck('total', 'state');

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
     * A record is counted once per distinct linked work-item status (links in
     * two statuses land in both buckets, duplicate statuses count once), plus an
     * Unknown bucket for links with a null state and a No work item bucket for
     * records with no link at all. Rows are therefore not additive.
     *
     * @return list<BreakdownRow>
     */
    public static function workItemStatusBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::WORK_ITEM_STATUS_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $statusRows = WorkItemLink::query()
                ->toBase()
                ->selectRaw('work_item_state, COUNT(DISTINCT event_id) as total')
                ->groupBy('work_item_state')
                ->orderByRaw('COUNT(DISTINCT event_id) DESC')
                ->get();

            $noWorkItem = SecurityEvent::query()->whereDoesntHave('workItemLinks')->count();

            return WorkItemStatusBreakdown::rows($statusRows, $noWorkItem);
        });

        return $result;
    }

    /**
     * Open alerts grouped by software system (top systems, then Others, then
     * Unassigned). Counts strictly state = open.
     *
     * @return list<BreakdownRow>
     */
    public static function openBySystemBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::SYSTEM_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $rows = SecurityEvent::query()
                ->toBase()
                ->leftJoin('software_systems', 'security_events.software_system_id', '=', 'software_systems.id')
                ->selectRaw('security_events.software_system_id as system_id, software_systems.name as system_name, COUNT(*) as total')
                ->where('security_events.state', EventState::Open->value)
                ->groupBy('security_events.software_system_id', 'software_systems.name')
                ->orderByRaw('COUNT(*) DESC')
                ->get();

            return OpenBySystemBreakdown::rows($rows);
        });

        return $result;
    }

    /**
     * Open alerts grouped by source, ordered by count descending. Counts
     * strictly state = open. (Local Findings have no source, so this pair has no
     * counterpart.)
     *
     * @return list<BreakdownRow>
     */
    public static function openBySourceBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::SOURCE_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $rows = SecurityEvent::query()
                ->toBase()
                ->selectRaw('source_id, COUNT(*) as total')
                ->where('state', EventState::Open->value)
                ->groupBy('source_id')
                ->orderByRaw('COUNT(*) DESC')
                ->get();

            return $rows->map(fn (object $row): array => [
                'key' => (string) $row->source_id,
                'label' => (string) $row->source_id,
                'count' => (int) $row->total,
                'color' => BreakdownColor::neutral((string) $row->source_id),
            ])->values()->all();
        });

        return $result;
    }

    /**
     * Open alerts grouped by type, in EventType order with zero buckets omitted.
     * Colors reuse the legacy type-distribution palette. Counts strictly
     * state = open.
     *
     * @return list<BreakdownRow>
     */
    public static function openByTypeBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::TYPE_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $counts = SecurityEvent::query()
                ->toBase()
                ->selectRaw('type, COUNT(*) as total')
                ->where('state', EventState::Open->value)
                ->groupBy('type')
                ->pluck('total', 'type');

            $palette = [
                EventType::Vulnerability->value => Color::Red,
                EventType::Secret->value => Color::Orange,
                EventType::Dependency->value => Color::Amber,
                EventType::License->value => Color::Yellow,
                EventType::Misconfiguration->value => Color::Blue,
                EventType::CodeQuality->value => Color::Purple,
                EventType::Iac->value => Color::Cyan,
                EventType::Posture->value => Color::Gray,
            ];

            $rows = [];
            foreach (EventType::cases() as $type) {
                $count = (int) ($counts[$type->value] ?? 0);

                if ($count === 0) {
                    continue;
                }

                $rows[] = [
                    'key' => $type->value,
                    'label' => str($type->value)->replace('_', ' ')->title()->toString(),
                    'count' => $count,
                    'color' => $palette[$type->value],
                ];
            }

            return $rows;
        });

        return $result;
    }

    /**
     * Open alerts grouped by severity, critical → informational with zero
     * buckets omitted. Colors via EventSeverityBadgeColor so pie slices and
     * list badges agree. Counts strictly state = open.
     *
     * @return list<BreakdownRow>
     */
    public static function openBySeverityBreakdown(): array
    {
        /** @var list<BreakdownRow> $result */
        $result = Cache::remember(self::SEVERITY_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $counts = SecurityEvent::query()
                ->toBase()
                ->selectRaw('severity, COUNT(*) as total')
                ->where('state', EventState::Open->value)
                ->groupBy('severity')
                ->pluck('total', 'severity');

            $rows = [];
            foreach (EventSeverity::cases() as $severity) {
                $count = (int) ($counts[$severity->value] ?? 0);

                if ($count === 0) {
                    continue;
                }

                $rows[] = [
                    'key' => $severity->value,
                    'label' => ucfirst($severity->value),
                    'count' => $count,
                    'color' => EventSeverityBadgeColor::for($severity),
                ];
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
        Cache::forget(self::SOURCE_CACHE_KEY);
        Cache::forget(self::TYPE_CACHE_KEY);
        Cache::forget(self::SEVERITY_CACHE_KEY);
    }
}
