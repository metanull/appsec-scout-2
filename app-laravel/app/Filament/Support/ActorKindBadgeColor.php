<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Shared badge color for AuditLog::actor_kind, used everywhere an audit
 * record's actor kind is rendered as a badge.
 */
final class ActorKindBadgeColor
{
    public static function for(?string $actorKind): string
    {
        return match ($actorKind) {
            'user' => 'info',
            'job' => 'gray',
            'cli' => 'warning',
            'system' => 'secondary',
            default => 'secondary',
        };
    }
}
