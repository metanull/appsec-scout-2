<?php

declare(strict_types=1);

use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Credentials\Credential;
use App\Credentials\Vault;
use App\Integrations\DispatchDueIntegrations;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Registry as TrackerRegistry;
use App\Triage\CodesearchService;
use App\Users\UserAdminService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

Artisan::command(
    'assets:import-attachment {source} {system} {kind} {file} '
    . '{--container=} {--system-name=} {--container-name=} {--container-kind=repository} '
    . '{--mime=} {--as=} {--user-id=}',
    function (AttachmentTargetResolver $resolver, AttachmentService $attachments, Filesystem $files): int {
        $sourceId = (string) $this->argument('source');
        $sourceSystemId = (string) $this->argument('system');
        $kind = (string) $this->argument('kind');
        $path = (string) $this->argument('file');

        if (! $files->exists($path) || ! $files->isFile($path)) {
            $this->error(sprintf('File not found: %s', $path));

            return self::FAILURE;
        }

        $containerSourceId = $this->option('container');
        $systemName = $this->option('system-name');
        $containerName = $this->option('container-name');
        $containerKind = (string) $this->option('container-kind');
        $mimeOption = $this->option('mime');
        $mime = is_string($mimeOption) && $mimeOption !== '' ? $mimeOption : 'application/octet-stream';
        $asOption = $this->option('as');
        $name = is_string($asOption) && $asOption !== '' ? $asOption : basename($path);
        $userIdOption = $this->option('user-id');
        $userId = is_numeric($userIdOption) ? (int) $userIdOption : null;

        try {
            $owner = $resolver->resolveSystem($sourceId, $sourceSystemId, is_string($systemName) ? $systemName : null);

            if (is_string($containerSourceId) && $containerSourceId !== '') {
                $owner = $resolver->resolveContainer(
                    $owner,
                    $containerSourceId,
                    is_string($containerName) ? $containerName : null,
                    $containerKind,
                );
            }

            $attachment = $attachments->attachTo(
                owner: $owner,
                kind: $kind,
                mime: $mime,
                name: $name,
                payload: $files->get($path),
                createdByUserId: $userId,
                createdByCommand: 'assets:import-attachment',
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Attached "%s" (%d bytes, kind=%s) to %s #%d.',
            $attachment->name,
            $attachment->size_bytes,
            $attachment->kind,
            class_basename($owner),
            $owner->getKey(),
        ));

        return self::SUCCESS;
    },
)->purpose('Import a file (SBOM, dependency report, HTTP headers, pipeline run, ...) as an attachment on a software system or container');

Artisan::command('credentials:system:export {path}', function (SourceRegistry $sources, TrackerRegistry $trackers, Filesystem $files): int {
    $path = (string) $this->argument('path');

    if ($path === '') {
        $this->error('The export path is required.');

        return self::FAILURE;
    }

    $integrations = [];

    foreach ($sources->all() as $source) {
        $fields = [];

        foreach ($source->credentialFields() as $field) {
            $fields[$field->key] = app(Vault::class)->get($field->key, null, true);
        }

        $integrations[$source->id()] = [
            'type' => 'source',
            'display_name' => $source->displayName(),
            'fields' => $fields,
        ];
    }

    foreach ($trackers->all() as $tracker) {
        $fields = [];

        foreach ($tracker->credentialFields() as $field) {
            $fields[$field->key] = app(Vault::class)->get($field->key, null, true);
        }

        $integrations[$tracker->id()] = [
            'type' => 'tracker',
            'display_name' => $tracker->displayName(),
            'fields' => $fields,
        ];
    }

    $payload = [
        'version' => 1,
        'owner' => 'system',
        'exported_at' => now()->toIso8601String(),
        'integrations' => $integrations,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $directory = dirname($path);

    if (! $files->isDirectory($directory)) {
        $files->makeDirectory($directory, 0755, true);
    }

    $files->put($path, $json . PHP_EOL);

    $this->info(sprintf('Exported system credentials to %s.', $path));

    return self::SUCCESS;
})->purpose('Export system credentials to a JSON file');

Artisan::command('credentials:system:import {path}', function (SourceRegistry $sources, TrackerRegistry $trackers, Filesystem $files): int {
    $path = (string) $this->argument('path');

    if (! $files->exists($path) || ! $files->isFile($path)) {
        $this->error(sprintf('File not found: %s', $path));

        return self::FAILURE;
    }

    $raw = $files->get($path);
    $raw = str_replace("\r\n", "\n", $raw);

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $this->error('Invalid JSON: ' . $exception->getMessage());

        return self::FAILURE;
    }

    if (! is_array($decoded)) {
        $this->error('Invalid structure: root object is required.');

        return self::FAILURE;
    }

    if (($decoded['version'] ?? null) !== 1 || ($decoded['owner'] ?? null) !== 'system') {
        $this->error('Invalid structure: expected version=1 and owner=system.');

        return self::FAILURE;
    }

    if (! array_key_exists('integrations', $decoded) || ! is_array($decoded['integrations'])) {
        $this->error('Invalid structure: integrations object is required.');

        return self::FAILURE;
    }

    $known = [];

    foreach ($sources->all() as $source) {
        $known[$source->id()] = [
            'type' => 'source',
            'fields' => array_map(fn ($field): string => $field->key, $source->credentialFields()),
        ];
    }

    foreach ($trackers->all() as $tracker) {
        $known[$tracker->id()] = [
            'type' => 'tracker',
            'fields' => array_map(fn ($field): string => $field->key, $tracker->credentialFields()),
        ];
    }

    $incoming = $decoded['integrations'];

    if (array_keys($incoming) !== array_values(array_filter(array_keys($incoming), 'is_string'))) {
        $this->error('Invalid structure: integrations keys must be integration IDs.');

        return self::FAILURE;
    }

    foreach ($known as $integrationId => $meta) {
        if (! array_key_exists($integrationId, $incoming) || ! is_array($incoming[$integrationId])) {
            $this->error(sprintf('Invalid structure: missing integration block for %s.', $integrationId));

            return self::FAILURE;
        }

        $block = $incoming[$integrationId];

        if (($block['type'] ?? null) !== $meta['type']) {
            $this->error(sprintf('Invalid structure: integration %s has invalid type.', $integrationId));

            return self::FAILURE;
        }

        if (! array_key_exists('fields', $block) || ! is_array($block['fields'])) {
            $this->error(sprintf('Invalid structure: integration %s fields object is required.', $integrationId));

            return self::FAILURE;
        }

        $incomingFields = $block['fields'];
        $expectedFields = $meta['fields'];

        sort($expectedFields);
        $incomingFieldKeys = array_keys($incomingFields);
        sort($incomingFieldKeys);

        if ($incomingFieldKeys !== $expectedFields) {
            $this->error(sprintf('Invalid structure: integration %s fields mismatch.', $integrationId));

            return self::FAILURE;
        }

        foreach ($incomingFields as $key => $value) {
            if (! is_string($key) || (! is_string($value) && $value !== null)) {
                $this->error(sprintf('Invalid structure: integration %s contains invalid field values.', $integrationId));

                return self::FAILURE;
            }
        }
    }

    if (count($incoming) !== count($known)) {
        $this->error('Invalid structure: integration set does not match known integrations.');

        return self::FAILURE;
    }

    $vault = app(Vault::class);

    $knownFieldKeys = [];

    foreach ($known as $meta) {
        foreach ($meta['fields'] as $fieldKey) {
            $knownFieldKeys[] = $fieldKey;
        }
    }

    $knownFieldKeys = array_values(array_unique($knownFieldKeys));

    // Replace all imported system credentials atomically for known keys so key rotation
    // or previously unreadable encrypted payloads cannot break imports.
    Credential::query()
        ->whereNull('owner_user_id')
        ->whereIn('integration_key', $knownFieldKeys)
        ->delete();

    foreach ($known as $integrationId => $meta) {
        /** @var array<string, string|null> $fields */
        $fields = $incoming[$integrationId]['fields'];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            $vault->set($key, null, $value);
        }
    }

    $this->info(sprintf('Imported system credentials from %s.', $path));

    return self::SUCCESS;
})->purpose('Import system credentials from a JSON export file with strict structure validation');

Schedule::job(new PruneAuditLogs((int) config('audit.retain_days', 365)))->daily();
Schedule::job(new PruneErrorLogs((int) config('logging.error_retain_days', 90)))->daily();
Schedule::command('integrations:dispatch-due')->everyMinute()->withoutOverlapping()->name('integrations:dispatch-due');
