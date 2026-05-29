<?php

namespace App\Trackers;

use App\Models\SecurityEvent;
use App\Trackers\Reconciliation\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class ReconcileEventJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $eventId) {}

    public function handle(ReconciliationService $service): void
    {
        $event = SecurityEvent::query()->find($this->eventId);

        if (! $event instanceof SecurityEvent) {
            return;
        }

        $service->reconcileEvent($event);
    }
}
