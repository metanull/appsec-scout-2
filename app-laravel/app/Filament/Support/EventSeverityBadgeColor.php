<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Enums\EventSeverity;

/**
 * Shared badge color for EventSeverity, used everywhere a SecurityEvent
 * severity (current or pending) is rendered as a badge.
 */
final class EventSeverityBadgeColor
{
    public static function for(EventSeverity|string|null $severity): string
    {
        $value = $severity instanceof EventSeverity ? $severity->value : $severity;

        return match ($value) {
            EventSeverity::Critical->value => 'danger',
            EventSeverity::High->value => 'warning',
            EventSeverity::Medium->value => 'info',
            EventSeverity::Low->value => 'gray',
            default => 'secondary',
        };
    }
}
