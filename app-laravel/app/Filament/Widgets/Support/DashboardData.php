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

    public const SOURCE_WORKITEM_CACHE_KEY = 'dashboard:source-workitem-state';

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
     * Returns open alert counts per source grouped by whether they have a work item link.
     *
     * @return list<array{source_id: string, linked: int, unlinked: int}>
     */
    public static function openAlertsBySourceAndWorkItemState(): array
    {
        /** @var list<array{source_id: string, linked: int, unlinked: int}> $result */
        $result = Cache::remember(self::SOURCE_WORKITEM_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $openState = EventState::Open->value;

            $rows = SecurityEvent::query()
                ->toBase()
                ->selectRaw(
                    'source_id, ' .
                    'SUM(CASE WHEN EXISTS (SELECT 1 FROM work_item_links wil WHERE wil.event_id = security_events.id) THEN 1 ELSE 0 END) AS linked, ' .
                    'SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM work_item_links wil WHERE wil.event_id = security_events.id) THEN 1 ELSE 0 END) AS unlinked'
                )
                ->where('state', $openState)
                ->groupBy('source_id')
                ->orderBy('source_id')
                ->get();

            return $rows->map(fn (object $row): array => [
                'source_id' => (string) $row->source_id,
                'linked' => (int) $row->linked,
                'unlinked' => (int) $row->unlinked,
            ])->values()->all();
        });

        return $result;
    }

    /**
     * @return Collection<int, SyncRun>
     */
    public static function recentSyncRuns(): Collection
    {
        /** @var Collection<int, SyncRun> $result */
        $result = SyncRun::query()
            ->latest('started_at')
            ->limit(10)
            ->get();

        return $result;
    }

    public static function durationSeconds(SyncRun $run): ?int
    {
        if ($run->getRawOriginal('started_at') === null || $run->getRawOriginal('finished_at') === null) {
            return null;
        }

        $startedAt = Carbon::parse((string) $run->started_at);
        $finishedAt = Carbon::parse((string) $run->finished_at);

        return abs((int) $finishedAt->diffInSeconds($startedAt));
    }

    /**
     * Format a counts_json array into a compact human-readable summary.
     */
    public static function formatCounts(mixed $counts): string
    {
        if ($counts === null) {
            return 'No counts recorded';
        }

        if (! is_array($counts) || $counts === []) {
            return '0 changes';
        }

        $parts = [];

        $knownGroups = [
            'sys' => ['systems_created', 'systems_updated'],
            'ctr' => ['containers_created', 'containers_updated'],
            'evt' => ['events_created', 'events_updated'],
        ];

        foreach ($knownGroups as $label => $keys) {
            $created = (int) ($counts[$keys[0]] ?? 0);
            $updated = (int) ($counts[$keys[1]] ?? 0);

            if ($created > 0 || $updated > 0) {
                $parts[] = "{$label} +{$created}/~{$updated}";
            }
        }

        $pushed = (int) ($counts['events_pushed'] ?? $counts['pushed'] ?? 0);
        $failed = (int) ($counts['events_failed'] ?? $counts['failed'] ?? 0);

        if ($pushed > 0) {
            $parts[] = "pushed {$pushed}";
        }

        if ($failed > 0) {
            $parts[] = "failed {$failed}";
        }

        return $parts !== [] ? implode(', ', $parts) : '0 changes';
    }

    public static function flushCache(): void
    {
        Cache::forget(self::STATS_CACHE_KEY);
        Cache::forget(self::SEVERITY_CACHE_KEY);
        Cache::forget(self::RUNS_CACHE_KEY);
        Cache::forget(self::SOURCE_WORKITEM_CACHE_KEY);
    }
}
