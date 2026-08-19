<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Shared badge color for the status column on SyncRun, RepositoryCollectionRun,
 * and StaticAnalysisRun (and their Recent* dashboard widgets) — `running` and
 * `partial` share the same "still needs attention" warning color; only
 * SyncRun lacks a `partial` status, so it never reaches that branch.
 */
final class RunStatusBadgeColor
{
    public static function for(?string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'failure' => 'danger',
            'partial', 'running' => 'warning',
            default => 'warning',
        };
    }
}
