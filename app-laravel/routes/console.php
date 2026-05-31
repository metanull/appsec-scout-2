<?php

declare(strict_types=1);

use App\Integrations\DispatchDueIntegrations;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Jobs\UpdateTrivyDbJob;
use App\Triage\BfgService;
use App\Triage\CodesearchService;
use App\Triage\TrivyService;
use App\Users\UserAdminService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Exception\ExceptionInterface;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('integrations:dispatch-due', function (DispatchDueIntegrations $dispatcher): int {
    $count = $dispatcher->dispatchDue();

    $this->info(sprintf('Dispatched %d due integration job(s).', $count));

    return self::SUCCESS;
})->purpose('Dispatch due source fetch and tracker refresh jobs from database-backed integration settings');

Artisan::command('appsec:bootstrap-admin {--email=} {--password=} {--name=Admin} {--if-missing}', function (UserAdminService $users): int {
    $email = (string) $this->option('email');
    $password = (string) $this->option('password');
    $name = (string) $this->option('name');
    $ifMissing = (bool) $this->option('if-missing');

    if ($email === '' || $password === '') {
        $this->error('Both --email and --password are required.');

        return self::FAILURE;
    }

    try {
        $user = $users->bootstrapAdmin($name, $email, $password);
    } catch (RuntimeException $exception) {
        if ($ifMissing && str_contains($exception->getMessage(), 'can only be created when no users exist')) {
            $this->info('Bootstrap admin already present. Skipping because --if-missing was provided.');

            return self::SUCCESS;
        }

        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->info(sprintf('Created bootstrap admin %s <%s>.', $user->name, $user->email));

    return self::SUCCESS;
})->purpose('Create the first AppSec Scout admin account when no users exist');

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
Schedule::command('integrations:dispatch-due')->everyMinute()->withoutOverlapping()->name('integrations:dispatch-due');
