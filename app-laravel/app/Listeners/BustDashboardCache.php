<?php

namespace App\Listeners;

use App\Events\SyncRunFinished;
use App\Filament\Widgets\Support\AlertBreakdownData;
use App\Filament\Widgets\Support\DashboardData;
use App\Filament\Widgets\Support\LocalFindingBreakdownData;

class BustDashboardCache
{
    public function handle(SyncRunFinished $event): void
    {
        DashboardData::flushCache();
        AlertBreakdownData::flushCache();
        LocalFindingBreakdownData::flushCache();
    }
}
