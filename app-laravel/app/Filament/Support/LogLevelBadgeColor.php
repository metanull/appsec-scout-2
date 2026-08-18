<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Shared badge color for a Monolog/PSR-3 level string (case-insensitive),
 * used everywhere an ErrorLog level is rendered as a badge.
 */
final class LogLevelBadgeColor
{
    public static function for(?string $level): string
    {
        return match (strtolower((string) $level)) {
            'emergency', 'alert', 'critical', 'error' => 'danger',
            'warning' => 'warning',
            default => 'secondary',
        };
    }
}
