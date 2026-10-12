<?php

namespace App\Filament\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The gate shared by every operation meant for either audience — Admin operators (admin.queue)
 * or Sync operators (work-items.sync) — on the Operations page and on record pages that queue the
 * same kind of work, so a button's ->visible() and its handler's own authorization can't drift
 * apart.
 */
final class OperationsAuthorization
{
    public static function canQueueOrSync(): bool
    {
        return Gate::any(['admin.queue', 'work-items.sync']);
    }

    public static function authorizeQueueOrSync(): void
    {
        if (! self::canQueueOrSync()) {
            throw new AuthorizationException('This action requires the admin.queue or work-items.sync permission.');
        }
    }
}
