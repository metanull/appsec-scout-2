<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Enums\EventState;

/**
 * Shared badge color for EventState, used everywhere a SecurityEvent or
 * LocalFinding state/status is rendered as a badge.
 */
final class EventStateBadgeColor
{
    public static function for(EventState|string|null $state): string
    {
        $value = $state instanceof EventState ? $state->value : $state;

        return match ($value) {
            EventState::Resolved->value => 'success',
            EventState::Dismissed->value => 'gray',
            EventState::InProgress->value => 'info',
            EventState::Acknowledged->value => 'warning',
            default => 'danger',
        };
    }
}
