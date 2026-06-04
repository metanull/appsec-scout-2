<?php

namespace App\Trackers;

use App\Trackers\Reconciliation\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

final class ReconcileAllJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'reconcile-all';
    }

    public function handle(ReconciliationService $service): void
    {
        $results = $service->reconcileAll();
        $createdCount = count(array_filter($results, fn ($result): bool => $result->alreadyLinked === false));

        Cache::put('reconciliation:last_run_at', now()->toIso8601String());
        Cache::put('reconciliation:last_run_new_links', $createdCount);
    }
}
