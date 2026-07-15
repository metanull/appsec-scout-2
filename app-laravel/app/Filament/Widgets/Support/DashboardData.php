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
     * Summarizes a SyncRun's counts_json into "N alerts retrieved, W warning(s), E error(s)" —
     * a fetch run (systems/containers/events pulled from a Source) reports alerts retrieved as
     * events created+updated, with an error only when the whole run failed; a push run (staged
     * alert changes sent upstream) reports its own events_succeeded/events_resolved_local_only/
     * events_failed counts directly.
     */
    public static function formatCounts(SyncRun $run): string
    {
        $counts = $run->counts_json;
        $counts = is_array($counts) ? $counts : [];

        if (array_key_exists('events_succeeded', $counts)) {
            $retrieved = (int) ($counts['events_succeeded'] ?? 0);
            $warnings = (int) ($counts['events_resolved_local_only'] ?? 0);
            $errors = (int) ($counts['events_failed'] ?? 0);
        } else {
            $retrieved = (int) ($counts['events_created'] ?? 0) + (int) ($counts['events_updated'] ?? 0);
            $warnings = 0;
            $errors = $run->status === 'failure' ? 1 : 0;
        }

        return "{$retrieved} alerts retrieved, {$warnings} warning(s), {$errors} error(s)";
    }

    public static function flushCache(): void
    {
        Cache::forget(self::STATS_CACHE_KEY);
        Cache::forget(self::SEVERITY_CACHE_KEY);
        Cache::forget(self::RUNS_CACHE_KEY);
        Cache::forget(self::SOURCE_WORKITEM_CACHE_KEY);
    }
}
