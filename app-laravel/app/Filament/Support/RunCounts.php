<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;
use Illuminate\Support\Carbon;

/**
 * Formats the `repositories_considered/completed/failed` counts_json shape
 * shared by RepositoryCollectionRun and StaticAnalysisRun.
 *
 * SyncRun's counts_json is a different shape entirely (events_succeeded /
 * events_created / events_updated / events_failed, formatted by
 * App\Filament\Widgets\Support\DashboardData::formatCounts()) — that is not
 * a duplicate of this formatter and is intentionally left separate.
 */
final class RunCounts
{
    public static function format(mixed $countsJson): string
    {
        $counts = is_array($countsJson) ? $countsJson : [];

        $considered = (int) ($counts['repositories_considered'] ?? 0);
        $completed = (int) ($counts['repositories_completed'] ?? 0);
        $failed = (int) ($counts['repositories_failed'] ?? 0);

        return "{$completed} / {$considered} · {$failed} failed";
    }

    /**
     * Shared by the same two run models — SyncRun's own duration helper
     * (DashboardData::durationSeconds()) is intentionally kept separate,
     * mirroring format()'s SyncRun/counts_json split above.
     */
    public static function durationSeconds(RepositoryCollectionRun|StaticAnalysisRun $run): ?int
    {
        if ($run->getRawOriginal('started_at') === null || $run->getRawOriginal('finished_at') === null) {
            return null;
        }

        $startedAt = Carbon::parse((string) $run->started_at);
        $finishedAt = Carbon::parse((string) $run->finished_at);

        return abs((int) $finishedAt->diffInSeconds($startedAt));
    }
}
