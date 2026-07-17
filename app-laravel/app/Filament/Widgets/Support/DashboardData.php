<?php

namespace App\Filament\Widgets\Support;

use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class DashboardData
{
    public const RUNS_CACHE_KEY = 'dashboard:recent-sync-runs';

    /**
     * @return Collection<int, SyncRun>
     */
    public static function recentSyncRuns(): Collection
    {
        /** @var Collection<int, SyncRun> $result */
        $result = SyncRun::query()
            ->latest('started_at')
            ->limit(5)
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
        $counts = $run->getAttribute('counts_json');
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
        Cache::forget(self::RUNS_CACHE_KEY);
    }
}
