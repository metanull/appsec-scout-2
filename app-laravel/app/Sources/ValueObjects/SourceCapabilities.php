<?php

namespace App\Sources\ValueObjects;

use App\Models\Enums\EventType;

/**
 * `canAddComments` means the Source can attach a comment to a state/severity push (it rides along
 * as `pending_comment`) — it says nothing about pushing a comment on its own. `canPushStandaloneComment`
 * is a separate, distinct capability for that: a comment added independent of any state/severity
 * change (see `App\Triage\CommentManager`). No Source implements it today — none of AzDO, ASoC, or
 * Detectify expose an API for posting a comment independent of a status change — and there is no
 * `Source` contract method to act on it even if a future Source declared it; see
 * `App\Sync\PendingSyncResolver` for how that declared-but-unimplemented case is guarded against.
 */
final class SourceCapabilities
{
    /**
     * @param  list<EventType>  $supportedEventTypes
     */
    public function __construct(
        public readonly bool $hasContainers = false,
        public readonly bool $canUpdateState = false,
        public readonly bool $canUpdateSeverity = false,
        public readonly bool $canAddComments = false,
        public readonly bool $canPushStandaloneComment = false,
        public readonly array $supportedEventTypes = [],
    ) {}
}
