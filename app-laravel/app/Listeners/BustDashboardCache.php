<?php

namespace App\Listeners;

use App\Events\SyncRunFinished;
use App\Filament\Widgets\Support\DashboardData;

class BustDashboardCache
{
    public function handle(SyncRunFinished $event): void
    {
        DashboardData::flushCache();
    }
}
