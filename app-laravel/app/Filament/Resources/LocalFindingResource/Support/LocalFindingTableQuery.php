<?php

namespace App\Filament\Resources\LocalFindingResource\Support;

use App\Models\LocalFinding;
use Illuminate\Database\Eloquent\Builder;

final class LocalFindingTableQuery
{
    /**
     * Portable rank over the effective (override-aware) severity, normalized to
     * lowercase so the scanner-reported (uppercase) and operator-overridden
     * (lowercase) values rank identically on MySQL 8 and SQLite. Unmapped
     * values (e.g. UNKNOWN) rank 0.
     */
    public static function effectiveSeverityRankSql(): string
    {
        return 'CASE LOWER(COALESCE(overridden_severity, severity))'
            . " WHEN 'critical' THEN 5 WHEN 'high' THEN 4 WHEN 'medium' THEN 3 WHEN 'low' THEN 2 WHEN 'informational' THEN 1"
            . ' ELSE 0 END';
    }
}
