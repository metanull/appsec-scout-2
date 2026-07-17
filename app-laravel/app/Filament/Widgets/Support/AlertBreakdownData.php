<?php

namespace App\Filament\Widgets\Support;

use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\WorkItemLink;
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

    public static function flushCache(): void
    {
        Cache::forget(self::STATE_CACHE_KEY);
        Cache::forget(self::WORK_ITEM_STATUS_CACHE_KEY);
    }
}
