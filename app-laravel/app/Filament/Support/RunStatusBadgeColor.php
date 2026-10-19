<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Shared badge color for the status column on SyncRun, RepositoryCollectionRun,
 * and StaticAnalysisRun (and their Recent* dashboard widgets) — `running` and
 * `partial` share the same "still needs attention" warning color (a SyncRun is
 * `partial` only for a reconciliation sweep where some tracker projects failed).
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
