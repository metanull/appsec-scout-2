<?php

use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PruneAuditLogs((int) config('audit.retain_days', 365)))->daily();
Schedule::job(new PruneErrorLogs((int) config('logging.error_retain_days', 90)))->daily();
