<?php

namespace App\Mcp\Support;

use App\Assets\Sbom\ComponentAlertMatcher;
use App\Assets\Sbom\ComponentVulnerabilityMatcher;
use App\Models\Enums\EventSeverity;
use App\Models\LocalFinding;
use App\Models\SecurityEvent;

/**
 * One severity scale for local findings and alerts together: the five levels, most severe first,
 * then "unknown" (scanner severities outside the five levels).
 */
final class SeverityRank
{
    public static function ofFinding(LocalFinding $finding): string
    {
        return ComponentVulnerabilityMatcher::bucket($finding);
    }

    public static function ofAlert(SecurityEvent $alert): string
    {
        return ComponentAlertMatcher::bucket($alert);
    }

    /**
     * The most severe of $severities, or null when there is none.
     *
     * @param  iterable<string|null>  $severities
     */
    public static function highest(iterable $severities): ?string
    {
        $highest = null;

        foreach ($severities as $severity) {
            if ($severity !== null && ($highest === null || self::rank($severity) > self::rank($highest))) {
                $highest = $severity;
            }
        }

        return $highest;
    }

    /** Higher is more severe; "unknown" ranks lowest. */
    public static function rank(string $severity): int
    {
        $scale = array_reverse(array_map(fn (EventSeverity $level): string => $level->value, EventSeverity::cases()));
        $position = array_search($severity, $scale, true);

        return $position === false ? -1 : $position;
    }
}
