<?php

namespace App\Filament\Widgets\Support;

use App\Filament\Support\EventStateBadgeColor;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
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

    public static function flushCache(): void
    {
        Cache::forget(self::STATE_CACHE_KEY);
    }
}
