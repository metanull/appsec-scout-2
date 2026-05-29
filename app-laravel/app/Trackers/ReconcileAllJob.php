<?php

namespace App\Trackers;

use App\Trackers\Reconciliation\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

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
        $service->reconcileAll();
    }
}
