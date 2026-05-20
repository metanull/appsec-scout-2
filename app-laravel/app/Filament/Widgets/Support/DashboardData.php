<?php

namespace App\Filament\Widgets\Support;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class DashboardData
{
    private const CACHE_TTL_SECONDS = 300;

    public const STATS_CACHE_KEY = 'dashboard:stats';

    public const SEVERITY_CACHE_KEY = 'dashboard:severity-distribution';

    public const RUNS_CACHE_KEY = 'dashboard:recent-sync-runs';

    /**
     * @return array{totalOpen: int, severities: array<string, int>, states: array<string, int>}
     */
    public static function stats(): array
    {
        /** @var array{totalOpen: int, severities: array<string, int>, states: array<string, int>} $result */
        $result = Cache::remember(self::STATS_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $severityCounts = SecurityEvent::query()
                ->toBase()
                ->selectRaw('severity, COUNT(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity');

            $stateCounts = SecurityEvent::query()
                ->toBase()
                ->selectRaw('state, COUNT(*) as total')
                ->groupBy('state')
                ->pluck('total', 'state');

            $severities = [];
            foreach (EventSeverity::cases() as $severity) {
                $severities[$severity->value] = (int) ($severityCounts[$severity->value] ?? 0);
            }

            $states = [];
            foreach (EventState::cases() as $state) {
                $states[$state->value] = (int) ($stateCounts[$state->value] ?? 0);
            }

            return [
                'totalOpen' => $states[EventState::Open->value],
                'severities' => $severities,
                'states' => $states,
            ];
        });

        return $result;
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>}>}
     */
    public static function severityChart(): array
    {
        /** @var array{labels: list<string>, datasets: list<array{label: string, data: list<int>}>} $result */
        $result = Cache::remember(self::SEVERITY_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $stats = self::stats();

            return [
                'labels' => ['Critical', 'High', 'Medium', 'Low', 'Informational'],
                'datasets' => [[
                    'label' => 'Alerts',
                    'data' => [
                        $stats['severities'][EventSeverity::Critical->value],
                        $stats['severities'][EventSeverity::High->value],
                        $stats['severities'][EventSeverity::Medium->value],
                        $stats['severities'][EventSeverity::Low->value],
                        $stats['severities'][EventSeverity::Informational->value],
                    ],
                ]],
            ];
        });

        return $result;
    }

    /**
     * @return Collection<int, SyncRun>
     */
    public static function recentSyncRuns(): Collection
    {
        /** @var Collection<int, SyncRun> $result */
        $result = Cache::remember(self::RUNS_CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => SyncRun::query()
            ->latest('started_at')
            ->limit(10)
            ->get());

        return $result;
    }

    public static function durationSeconds(SyncRun $run): ?int
    {
        if ($run->getRawOriginal('started_at') === null || $run->getRawOriginal('finished_at') === null) {
            return null;
        }

        $startedAt = Carbon::parse((string) $run->started_at);
        $finishedAt = Carbon::parse((string) $run->finished_at);

        return (int) $finishedAt->diffInSeconds($startedAt);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::STATS_CACHE_KEY);
        Cache::forget(self::SEVERITY_CACHE_KEY);
        Cache::forget(self::RUNS_CACHE_KEY);
    }
}
