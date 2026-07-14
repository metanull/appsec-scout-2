<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Models\ErrorLog;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Sources\Contracts\Source;
use App\Sources\ValueObjects\SourceCapabilities;

/**
 * Decides whether a dirty `SecurityEvent`'s staged changes are actually awaiting a push for its
 * Source, and resolves the ones that aren't: a severity-only change the Source can't accept, or a
 * standalone comment (no Source can ever push a comment independent of a state/severity change —
 * see `SourceCapabilities`). Shared by `PushEventStatesJob` (resolves as part of a live push run)
 * and `events:recompute-pending-sync` (a one-off backfill for events dirtied before this class
 * existed).
 */
final class PendingSyncResolver
{
    public function __construct(private readonly Recorder $recorder) {}

    public function hasPushableChange(SecurityEvent $event, SourceCapabilities $capabilities): bool
    {
        $statePending = $event->pending_state !== null;
        $severityPending = $event->pending_severity !== null;

        return $statePending || ($severityPending && $capabilities->canUpdateSeverity);
    }

    /**
     * Resolves an event with no pushable change right now. Leaves a system-authored note on the
     * event plus an ErrorLog warning, then clears `is_dirty` — the staged value (e.g.
     * `pending_severity`) is left untouched so it stays visible as a durable local annotation.
     *
     * Returns false (and does *not* resolve/clear anything) if the event declares
     * `canPushStandaloneComment` with no Source contract mechanism to act on it — a
     * misconfiguration that must surface loudly rather than be silently treated as resolved.
     */
    public function resolveUnpushableChange(
        SecurityEvent $event,
        Source $source,
        SourceCapabilities $capabilities,
        ?int $operatorUserId,
    ): bool {
        $sourceId = $source->id();
        $severityPending = $event->pending_severity !== null;

        if (! $severityPending && $capabilities->canPushStandaloneComment) {
            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'sync',
                'message' => sprintf(
                    'Source "%s" declares canPushStandaloneComment=true, but no mechanism exists to push a standalone comment.',
                    $sourceId,
                ),
                'context_json' => ['event_id' => $event->id, 'source_id' => $sourceId],
                'trace' => null,
                'occurred_at' => now(),
            ]);

            return false;
        }

        $note = $severityPending
            ? $this->unsupportedSeverityNote($event, $source)
            : sprintf(
                'This comment could not be pushed: %s does not support receiving a comment independent of a state or severity change. It stays local-only.',
                $source->displayName(),
            );

        $this->recordLocalOnlyResolution($event, $sourceId, $note, $operatorUserId);

        $event->forceFill([
            'is_dirty' => false,
            'updated_at' => now(),
        ])->save();

        return true;
    }

    public function unsupportedSeverityNote(SecurityEvent $event, Source $source): string
    {
        return sprintf(
            'Severity change to "%s" could not be pushed: %s does not support updating alert severity. This stays a local-only annotation.',
            $event->pending_severity?->value,
            $source->displayName(),
        );
    }

    /**
     * Leaves a system-authored `EventComment` and an ErrorLog warning explaining why a staged
     * change was resolved as local-only rather than pushed. Does not touch `is_dirty` itself —
     * callers decide that, since a successful state push that still leaves an unsupported severity
     * change outstanding needs to preserve the push's own success outcome.
     */
    public function recordLocalOnlyResolution(SecurityEvent $event, string $sourceId, string $note, ?int $operatorUserId): void
    {
        EventComment::query()->create([
            'event_id' => $event->id,
            'body' => $note,
            'author_user_id' => null,
            'created_at' => now(),
        ]);

        ErrorLog::query()->create([
            'level' => 'warning',
            'channel' => 'sync',
            'message' => $note,
            'context_json' => ['event_id' => $event->id, 'source_id' => $sourceId],
            'trace' => null,
            'occurred_at' => now(),
        ]);

        $this->recorder->recordSyncPush(SecurityEvent::class, (string) $event->id, array_filter([
            'status' => 'local_only',
            'source_id' => $sourceId,
            'operator_user_id' => $operatorUserId,
        ], fn (mixed $value): bool => $value !== null));
    }
}
