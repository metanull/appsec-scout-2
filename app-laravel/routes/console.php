<?php

declare(strict_types=1);

use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Jobs\UpdateTrivyDbJob;
use App\Sources\Registry;
use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry as TrackerRegistry;
use App\Triage\BfgService;
use App\Triage\CodesearchService;
use App\Triage\TrivyService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Exception\ExceptionInterface;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('triage:codesearch {pat} {search} {--scope=} {--attach-to=}', function (): int {
    $pat = (string) $this->argument('pat');
    $search = (string) $this->argument('search');
    $scope = $this->option('scope');
    $attachTo = $this->option('attach-to');

    try {
        $result = app(CodesearchService::class)->run(
            pat: $pat,
            searchText: $search,
            scope: is_string($scope) ? $scope : null,
            attachToEventId: is_numeric($attachTo) ? (int) $attachTo : null,
        );
    } catch (InvalidArgumentException|RuntimeException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info(sprintf('Found %d code search results.', $result->totalCount()));

    $rows = array_map(
        fn (array $row): array => [$row['project'], $row['repository'], $row['path'], $row['matches']],
        array_slice($result->tableRows(), 0, 10),
    );

    if ($rows !== []) {
        $this->table(['Project', 'Repository', 'Path', 'Matches'], $rows);
    }

    if (is_numeric($attachTo)) {
        $this->info(sprintf('Attached code search JSON to alert %d.', (int) $attachTo));
    }

    return 0;
})->purpose('Run Azure DevOps code search and optionally attach the JSON result to an alert');

Artisan::command('triage:trivy {git_url} {--attach-to=}', function (): int {
    $gitUrl = (string) $this->argument('git_url');
    $attachTo = $this->option('attach-to');

    try {
        $result = app(TrivyService::class)->run(
            gitUrl: $gitUrl,
            attachToEventId: is_numeric($attachTo) ? (int) $attachTo : null,
        );
    } catch (InvalidArgumentException|RuntimeException|ExceptionInterface $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info('Trivy scan completed.');

    if ($result->attachmentId !== null) {
        $this->info(sprintf('Attached SARIF output to alert %d.', (int) $attachTo));
    }

    return 0;
})->purpose('Clone a repository, run Trivy, and optionally attach the SARIF result to an alert');

Artisan::command('triage:bfg {git_url} {secret_list_file} {--attach-to=}', function (): int {
    $gitUrl = (string) $this->argument('git_url');
    $secretListFile = (string) $this->argument('secret_list_file');
    $attachTo = $this->option('attach-to');

    try {
        $result = app(BfgService::class)->run(
            gitUrl: $gitUrl,
            secretListFile: $secretListFile,
            attachToEventId: is_numeric($attachTo) ? (int) $attachTo : null,
        );
    } catch (InvalidArgumentException|RuntimeException|ExceptionInterface $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info('BFG run completed.');

    if ($result->bundleAttachmentId !== null) {
        $this->info(sprintf(
            'Bundle saved at attachment %d. Review via git clone <bundle> and force-push manually if accepted.',
            $result->bundleAttachmentId,
        ));
    }

    return 0;
})->purpose('Clone a repository mirror, run BFG, and optionally attach the report and bundle to an alert');

Schedule::job(new PruneAuditLogs((int) config('audit.retain_days', 365)))->daily();
Schedule::job(new PruneErrorLogs((int) config('logging.error_retain_days', 90)))->daily();
Schedule::job(new UpdateTrivyDbJob)->daily()->name('update-trivy-db');

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

$enabledTrackers = app(TrackerRegistry::class)->enabled();

foreach ($enabledTrackers as $tracker) {
    $interval = (int) config("integration_settings.{$tracker->id()}.interval_minutes", 30);
    $interval = min(max($interval, 1), 60);

    $event = Schedule::job(new RefreshWorkItemsJob)->name('refresh-work-items:' . $tracker->id());

    if ($interval === 30) {
        $event->everyThirtyMinutes();

        continue;
    }

    $event->cron("*/{$interval} * * * *");
}
