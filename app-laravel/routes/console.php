<?php

use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Sources\Registry;
use App\Sync\FetchSourceJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PruneAuditLogs((int) config('audit.retain_days', 365)))->daily();
Schedule::job(new PruneErrorLogs((int) config('logging.error_retain_days', 90)))->daily();

$enabledSources = app(Registry::class)->enabled();

foreach ($enabledSources as $source) {
    $interval = (int) config("integration_settings.{$source->id()}.interval_minutes", 30);
    $interval = min(max($interval, 1), 60);

    $event = Schedule::job(new FetchSourceJob($source->id()));

    if ($interval === 30) {
        $event->everyThirtyMinutes();

        continue;
    }

    $event->cron("*/{$interval} * * * *");
}
