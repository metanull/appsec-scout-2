<?php

namespace App\Sync;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

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
        $counts = $service->sync();

        Cache::put('inventory_sync:last_run_at', now()->toIso8601String());
        Cache::put('inventory_sync:last_run_counts', $counts);
    }
}
