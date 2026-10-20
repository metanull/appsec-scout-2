<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Models\Enums\EventState;
use App\Models\ErrorLog;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Contracts\ReadsUpstreamState;
use App\Sources\Contracts\Source;
use App\Triage\OperatorIntegrationRuntime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Pushes pending alert state changes of one source upstream. A push is user-triggered, so it
 * runs synchronously with the operator's own source credential and reports what happened;
 * changes the source cannot accept are resolved locally (PendingSyncResolver), and a change the
 * source already holds (made there directly) is marked synced rather than failing on every push.
 */
final class PendingStatePusher
{
    public const MAX_RETRIES = 3;

    public function __construct(
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly Recorder $recorder,
        private readonly PendingSyncResolver $resolver,
    ) {}

    /** @param  Collection<int, SecurityEvent>  $events */
    public function push(Collection $events, User $operator): PushOutcome
    {
        $sourceId = $this->sourceOf($events);

        if ($this->runtime->source($sourceId) === null) {
            throw new InvalidArgumentException("Source {$sourceId} is not enabled, so its alerts cannot be pushed.");
        }

        $missing = $this->runtime->missingSourceCredentialLabels($sourceId, $operator->id);

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Pushing to %s uses your own credential, and yours is missing: %s. Add it under Profile → Integrations.',
                $sourceId,
                implode(', ', $missing),
            ));
        }

        $lock = Cache::lock('push-event-states:' . $sourceId, 600);

        if (! $lock->get()) {
            throw new InvalidArgumentException("A push to {$sourceId} is already running.");
        }

        try {
            // Re-read under the lock: another push may have changed these alerts meanwhile.
            $fresh = SecurityEvent::query()
                ->with(['softwareSystem', 'container.sourceSoftwareSystem'])
                ->whereKey($events->map(fn (SecurityEvent $event): int => $event->id)->all())
                ->where('is_dirty', true)
                ->get();

            return $this->pushLocked($fresh, $sourceId, $operator->id);
        } finally {
            $lock->release();
        }
    }

    /** @param  Collection<int, SecurityEvent>  $events */
    private function sourceOf(Collection $events): string
    {
        $sourceIds = $events->pluck('source_id')->unique()->values();

        if ($sourceIds->count() !== 1) {
            throw new InvalidArgumentException('A push covers the alerts of exactly one source.');
        }

        return (string) $sourceIds->first();
    }

    /** @param  Collection<int, SecurityEvent>  $events */
    private function pushLocked(Collection $events, string $sourceId, int $operatorUserId): PushOutcome
    {
        $run = SyncRun::query()->create([
            'source_id' => $sourceId,
            'started_at' => now(),
            'status' => 'running',
            'counts_json' => [],
        ]);

        $counts = [
            'events_succeeded' => 0,
            'events_failed' => 0,
            'events_skipped' => 0,
            'events_resolved_local_only' => 0,
            'events_already_upstream' => 0,
        ];
        $lastError = null;

        try {
            $this->runtime->runSource($sourceId, $operatorUserId, function (Source $source) use ($events, $sourceId, $operatorUserId, &$counts, &$lastError): void {
                foreach ($events as $event) {
                    $outcome = $this->pushEvent($source, $event, $sourceId, $operatorUserId);

                    foreach ($outcome['counts'] as $key => $increment) {
                        $counts[$key] += $increment;
                    }

                    $lastError = $outcome['error'] ?? $lastError;
                }
            });

            $run->update([
                'finished_at' => now(),
                'status' => $counts['events_failed'] > 0 ? 'failure' : 'success',
                'counts_json' => $counts,
                'error_message' => $lastError,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'status' => 'failure',
                'counts_json' => $counts,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return new PushOutcome(
            sourceId: $sourceId,
            succeeded: $counts['events_succeeded'],
            failed: $counts['events_failed'],
            skipped: $counts['events_skipped'],
            resolvedLocalOnly: $counts['events_resolved_local_only'],
            alreadyUpstream: $counts['events_already_upstream'],
            lastError: $lastError,
        );
    }

    /** @return array{counts: array<string, int>, error?: string|null} */
    private function pushEvent(Source $source, SecurityEvent $event, string $sourceId, int $operatorUserId): array
    {
        $capabilities = $source->capabilities();
        $metadata = self::metadataArray($event);
        $retryCount = min((int) ($metadata['pushRetryCount'] ?? 0), self::MAX_RETRIES);

        if (! $this->resolver->hasPushableChange($event, $capabilities)) {
            // A declared capability with no mechanism to act on it stays dirty and surfaced as an
            // error rather than silently pretending it was resolved.
            return $this->resolver->resolveUnpushableChange($event, $source, $capabilities, $operatorUserId)
                ? ['counts' => ['events_resolved_local_only' => 1]]
                : ['counts' => ['events_skipped' => 1]];
        }

        if ($retryCount >= self::MAX_RETRIES) {
            // No further push attempts, but the change may have been made at the source since.
            $upstream = $this->upstreamState($source, $event);

            return $upstream === $event->getAttribute('pending_state')
                ? $this->markPushed($source, $event, $metadata, $sourceId, $operatorUserId, alreadyUpstream: true)
                : ['counts' => ['events_skipped' => 1]];
        }

        $result = $source->pushEventState($event);

        if ($result->ok) {
            return $this->markPushed($source, $event, $metadata, $sourceId, $operatorUserId, alreadyUpstream: false);
        }

        // A source refuses a change it already holds (e.g. AzDO answers 409 Conflict for an alert
        // dismissed upstream), so a failed push checks whether the source already has the state.
        $upstream = $this->upstreamState($source, $event);

        if ($upstream === $event->getAttribute('pending_state')) {
            return $this->markPushed($source, $event, $metadata, $sourceId, $operatorUserId, alreadyUpstream: true);
        }

        $error = is_string($upstream)
            ? sprintf('%s (Reading the alert back from the source also failed: %s)', $result->error ?? 'Push failed.', $upstream)
            : $result->error;

        $this->recordFailure($event, $metadata, $retryCount + 1, $error, $sourceId, $operatorUserId);

        return ['counts' => ['events_failed' => 1], 'error' => $error];
    }

    /** The alert's current state at the source, or why it could not be read. */
    private function upstreamState(Source $source, SecurityEvent $event): EventState|string
    {
        try {
            return $source instanceof ReadsUpstreamState
                ? $source->fetchUpstreamState($event)
                : $source->fetchRawEvent($event)->state;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Applies a pending state the source now holds: pushed just now, or found already set there
     * (`$alreadyUpstream`), in which case a note explains that the local comment was not pushed.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{counts: array<string, int>}
     */
    private function markPushed(Source $source, SecurityEvent $event, array $metadata, string $sourceId, int $operatorUserId, bool $alreadyUpstream): array
    {
        $capabilities = $source->capabilities();
        $pendingState = $event->getAttribute('pending_state');

        unset($metadata['pushRetryCount'], $metadata['lastPushError']);

        $stillHasUnsupportedSeverity = $event->pending_severity !== null && ! $capabilities->canUpdateSeverity;

        $event->forceFill([
            'state' => $event->pending_state,
            'pending_state' => null,
            'pending_dismissal_reason' => null,
            'pending_comment' => null,
            'is_dirty' => $event->pending_severity !== null && $capabilities->canUpdateSeverity,
            'metadata' => $metadata,
            'synced_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->recorder->recordSyncPush(SecurityEvent::class, (string) $event->id, [
            'status' => $alreadyUpstream ? 'already_upstream' : 'success',
            'source_id' => $sourceId,
            'operator_user_id' => $operatorUserId,
        ]);

        $counts = [$alreadyUpstream ? 'events_already_upstream' : 'events_succeeded' => 1];

        if ($alreadyUpstream) {
            EventComment::query()->create([
                'event_id' => $event->id,
                'body' => sprintf(
                    '%s already had this alert as "%s", so the pending change was marked as synced without pushing it. The justification comment stays local-only.',
                    $source->displayName(),
                    $pendingState instanceof EventState ? $pendingState->value : (string) $pendingState,
                ),
                'author_user_id' => null,
                'created_at' => now(),
            ]);
        }

        if (! $stillHasUnsupportedSeverity) {
            return ['counts' => $counts];
        }

        $this->resolver->recordLocalOnlyResolution(
            $event,
            $sourceId,
            $this->resolver->unsupportedSeverityNote($event, $source),
            $operatorUserId,
        );

        return ['counts' => $counts + ['events_resolved_local_only' => 1]];
    }

    /** @param  array<string, mixed>  $metadata */
    private function recordFailure(SecurityEvent $event, array $metadata, int $retryCount, ?string $error, string $sourceId, int $operatorUserId): void
    {
        $retryCount = min($retryCount, self::MAX_RETRIES);
        $metadata['pushRetryCount'] = $retryCount;
        $metadata['lastPushError'] = $error;

        $event->forceFill([
            'is_dirty' => true,
            'metadata' => $metadata,
            'updated_at' => now(),
        ])->save();

        ErrorLog::query()->create([
            'level' => 'error',
            'channel' => 'sync',
            'software_system_id' => $event->software_system_id,
            'security_container_id' => $event->container_id,
            'message' => $error ?? 'Push failed.',
            'context_json' => [
                'event_id' => $event->id,
                'source_id' => $sourceId,
                'retry_count' => $retryCount,
            ],
            'trace' => null,
            'occurred_at' => now(),
        ]);

        $this->recorder->recordSyncPush(SecurityEvent::class, (string) $event->id, [
            'status' => 'failure',
            'source_id' => $sourceId,
            'operator_user_id' => $operatorUserId,
            'error' => $error,
            'retry_count' => $retryCount,
        ]);
    }

    /** @return array<string, mixed> */
    private static function metadataArray(SecurityEvent $event): array
    {
        $metadata = $event->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }
}
