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

    /**
     * A sweep waits out GitHub's Search API rate limit (about 30 searches a minute) across
     * every repository the system token can see, so it can run for several minutes; the
     * uniqueness lock must outlive it so a second sweep cannot start alongside.
     */
    public int $timeout = 1800;

    public int $uniqueFor = 1800;

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
            $sweep = $service->reconcileAll();
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

        // Each failed tracker project already has its own ErrorLog row (written by the
        // service as it happened); the run carries the counts and a summary.
        $run->update([
            'finished_at' => now(),
            'status' => match (true) {
                $sweep->projectsFailed() === 0 => 'success',
                $sweep->projectsReconciled === 0 => 'failure',
                default => 'partial',
            },
            'counts_json' => [
                'links_created' => $sweep->linksCreated(),
                'links_existing' => $sweep->linksExisting(),
                'projects_reconciled' => $sweep->projectsReconciled,
                'projects_failed' => $sweep->projectsFailed(),
            ],
            'error_message' => $sweep->failureSummary(),
        ]);

        event(new SyncRunFinished($run));
    }
}
