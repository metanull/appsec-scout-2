<?php

namespace App\Trackers;

use App\Events\SyncRunFinished;
use App\Models\ErrorLog;
use App\Models\SyncRun;
use App\Trackers\Reconciliation\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Throwable;

final class ReconcileAllJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Pseudo-source under which a full reconciliation sweep records itself in the
     * generic sync_runs history, the same way InventorySyncService records 'inventory'.
     */
    public const string RUN_SOURCE_ID = 'reconciliation';

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'reconcile-all';
    }

    public function handle(ReconciliationService $service): void
    {
        $run = SyncRun::query()->create([
            'source_id' => self::RUN_SOURCE_ID,
            'started_at' => now(),
            'status' => 'running',
            'counts_json' => [],
        ]);

        try {
            $results = $service->reconcileAll();
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage());
            $message = Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 1000);

            $run->update([
                'finished_at' => now(),
                'status' => 'failure',
                'counts_json' => ['links_created' => 0, 'links_existing' => 0],
                'error_message' => $message,
            ]);

            ErrorLog::query()->create([
                'level' => 'error',
                'channel' => 'reconciliation',
                'message' => $message,
                'context_json' => [
                    'source_id' => self::RUN_SOURCE_ID,
                ],
                'trace' => $exception->getTraceAsString(),
                'occurred_at' => now(),
            ]);

            event(new SyncRunFinished($run));

            throw $exception;
        }

        $created = count(array_filter($results, fn ($result): bool => $result->alreadyLinked === false));
        $existing = count($results) - $created;

        $run->update([
            'finished_at' => now(),
            'status' => 'success',
            'counts_json' => ['links_created' => $created, 'links_existing' => $existing],
            'error_message' => null,
        ]);

        event(new SyncRunFinished($run));
    }
}
