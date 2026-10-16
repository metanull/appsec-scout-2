<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drops the unpushed local changes of dirty alerts (pending state, severity and comment, plus
 * the retry bookkeeping of failed pushes), so an alert whose change the source refuses — or
 * that the operator no longer wants pushed — leaves Pending Sync. The alert keeps the state
 * last read from its source; a note on the alert and an audit entry record what was dropped.
 */
final class PendingChangeDiscarder
{
    public function __construct(private readonly Recorder $recorder) {}

    /**
     * @param  iterable<SecurityEvent>  $events
     * @return int the number of alerts whose pending change was discarded
     */
    public function discard(iterable $events, User $actor): int
    {
        return DB::transaction(function () use ($events, $actor): int {
            $discarded = 0;

            foreach ($events as $event) {
                if (! $event->is_dirty) {
                    continue;
                }

                $this->discardOne($event, $actor);
                $discarded++;
            }

            return $discarded;
        });
    }

    private function discardOne(SecurityEvent $event, User $actor): void
    {
        $metadata = $event->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $dropped = [
            'pending_state' => self::value($event->getAttribute('pending_state')),
            'pending_severity' => self::value($event->getAttribute('pending_severity')),
            'pending_comment' => $event->pending_comment,
            'push_retry_count' => (int) ($metadata['pushRetryCount'] ?? 0),
            'last_push_error' => is_string($metadata['lastPushError'] ?? null) ? $metadata['lastPushError'] : null,
        ];

        unset($metadata['pushRetryCount'], $metadata['lastPushError']);

        $event->forceFill([
            'pending_state' => null,
            'pending_severity' => null,
            'pending_comment' => null,
            'is_dirty' => false,
            'metadata' => $metadata,
            'updated_at' => now(),
        ])->save();

        EventComment::query()->create([
            'event_id' => $event->id,
            'body' => self::note($dropped['pending_state'], $dropped['pending_severity'], self::value($event->getAttribute('state'))),
            'author_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $this->recorder->recordPendingChangeDiscarded(SecurityEvent::class, (string) $event->id, [
            'discarded' => $dropped,
            'actor_user_id' => $actor->id,
        ]);
    }

    private static function note(?string $pendingState, ?string $pendingSeverity, ?string $state): string
    {
        $changes = array_filter([
            $pendingState !== null ? "state → {$pendingState}" : null,
            $pendingSeverity !== null ? "severity → {$pendingSeverity}" : null,
        ]);

        return sprintf(
            '[Pending change discarded] %s was not pushed to the source; the alert stays "%s" as last read from the source.',
            $changes !== [] ? 'The unpushed change (' . implode(', ', $changes) . ')' : 'The unpushed comment',
            $state ?? '-',
        );
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof EventState || $value instanceof EventSeverity ? $value->value : (is_string($value) ? $value : null);
    }
}
