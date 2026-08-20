<?php

namespace App\Sync;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class SyncInventoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'sync-inventory';
    }

    public function handle(InventorySyncService $service): void
    {
        $service->sync();
    }
}
