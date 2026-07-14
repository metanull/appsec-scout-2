<?php

namespace App\Sync;

use App\Audit\Recorder;
use App\Integrations\OperatorIntegrationRuntime;
use App\Models\ErrorLog;
use App\Models\SecurityEvent;
use App\Models\SyncRun;
use App\Sources\Contracts\Source;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class PushEventStatesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @param  list<int>  $eventIds */
    public function __construct(public readonly array $eventIds, public readonly int $operatorUserId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $sourceId = SecurityEvent::query()
            ->whereKey($this->eventIds)
            ->value('source_id');

        if (! is_string($sourceId) || $sourceId === '') {
            return [];
        }

        return [new WithoutOverlapping('push-event-states:' . $sourceId)];
    }

    public function handle(OperatorIntegrationRuntime $runtime, Recorder $recorder, PendingSyncResolver $resolver): void
    {
        $events = SecurityEvent::query()
            ->with('softwareSystem')
            ->whereKey($this->eventIds)
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $sourceIds = $events->pluck('source_id')->unique()->values();

        if ($sourceIds->count() !== 1) {
            throw new \RuntimeException('PushEventStatesJob must receive events for a single source.');
        }

        $sourceId = (string) $sourceIds->first();
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
        ];
        $lastError = null;

        try {
            $runtime->runSource($sourceId, $this->operatorUserId, function (Source $source) use ($events, $sourceId, $recorder, $resolver, &$counts, &$lastError): void {
                $capabilities = $source->capabilities();

                foreach ($events as $event) {
                    $metadata = self::metadataArray($event);
                    $retryCount = min((int) ($metadata['pushRetryCount'] ?? 0), 3);

                    if (! $resolver->hasPushableChange($event, $capabilities)) {
                        if ($resolver->resolveUnpushableChange($event, $source, $capabilities, $this->operatorUserId)) {
                            $counts['events_resolved_local_only']++;
                        } else {
                            // Declared a capability with no mechanism to act on it — leave the
                            // event dirty and surfaced as an error rather than silently
                            // pretending it was resolved.
                            $counts['events_skipped']++;
                        }

                        continue;
                    }

                    if ($retryCount >= 3) {
                        $counts['events_skipped']++;

                        continue;
                    }

                    $result = $source->pushEventState($event);

                    if ($result->ok) {
                        unset($metadata['pushRetryCount'], $metadata['lastPushError']);

                        $stillHasUnsupportedSeverity = $event->pending_severity !== null && ! $capabilities->canUpdateSeverity;

                        $event->forceFill([
                            'state' => $event->pending_state,
                            'pending_state' => null,
                            'pending_comment' => null,
                            'is_dirty' => $event->pending_severity !== null && $capabilities->canUpdateSeverity,
                            'metadata' => $metadata,
                            'synced_at' => now(),
                            'updated_at' => now(),
                        ])->save();

                        $recorder->recordSyncPush(SecurityEvent::class, (string) $event->id, [
                            'status' => 'success',
                            'source_id' => $sourceId,
                            'operator_user_id' => $this->operatorUserId,
                        ]);

                        $counts['events_succeeded']++;

                        if ($stillHasUnsupportedSeverity) {
                            $resolver->recordLocalOnlyResolution(
                                $event,
                                $sourceId,
                                $resolver->unsupportedSeverityNote($event, $source),
                                $this->operatorUserId,
                            );
                            $counts['events_resolved_local_only']++;
                        }

                        continue;
                    }

                    $retryCount = min($retryCount + 1, 3);
                    $metadata['pushRetryCount'] = $retryCount;
                    $metadata['lastPushError'] = $result->error;

                    $event->forceFill([
                        'is_dirty' => true,
                        'metadata' => $metadata,
                        'updated_at' => now(),
                    ])->save();

                    ErrorLog::query()->create([
                        'level' => 'error',
                        'channel' => 'sync',
                        'message' => $result->error ?? 'Push failed.',
                        'context_json' => [
                            'event_id' => $event->id,
                            'source_id' => $sourceId,
                            'retry_count' => $retryCount,
                        ],
                        'trace' => null,
                        'occurred_at' => now(),
                    ]);

                    $recorder->recordSyncPush(SecurityEvent::class, (string) $event->id, [
                        'status' => 'failure',
                        'source_id' => $sourceId,
                        'operator_user_id' => $this->operatorUserId,
                        'error' => $result->error,
                        'retry_count' => $retryCount,
                    ]);

                    $counts['events_failed']++;
                    $lastError = $result->error;
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
    }

    /** @return array<string, mixed> */
    private static function metadataArray(SecurityEvent $event): array
    {
        $metadata = $event->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }
}
