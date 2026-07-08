<?php

declare(strict_types=1);

use App\Assets\AttachmentIngestionService;
use App\Assets\AttachmentService;
use App\Assets\AttachmentTargetResolver;
use App\Assets\AzDoProjectLinker;
use App\Assets\DependencyTrack\DependencyTrackAdminClientFactory;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Assets\DependencyTrack\DependencyTrackExporter;
use App\Credentials\Credential;
use App\Credentials\Vault;
use App\Integrations\DispatchDueIntegrations;
use App\Integrations\SystemIntegrationRuntime;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Sync\SystemContainerUpserter;
use App\Trackers\Registry as TrackerRegistry;
use App\Triage\CodesearchService;
use App\Users\UserAdminService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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

Artisan::command(
    'assets:sync-azdo-projects {--project-filter=} {--repo-filter=}',
    function (SystemIntegrationRuntime $runtime, SystemContainerUpserter $upserter, AzDoProjectLinker $linker): int {
        $projectFilter = $this->option('project-filter');
        $repoFilter = $this->option('repo-filter');

        $matches = static function (?string $pattern, string $value): bool {
            if (! is_string($pattern) || $pattern === '') {
                return true;
            }

            return @preg_match('~' . str_replace('~', '\~', $pattern) . '~', $value) === 1;
        };

        $counts = [
            'projects_seen' => 0,
            'systems_created' => 0,
            'systems_updated' => 0,
            'assets_created' => 0,
            'repos_seen' => 0,
            'containers_created' => 0,
            'containers_updated' => 0,
            'repository_mappings_created' => 0,
        ];

        try {
            $runtime->runSource(AzDoNormalizer::SOURCE_ID, function (Source $source) use ($matches, $projectFilter, $repoFilter, $upserter, $linker, &$counts): void {
                foreach ($source->fetchSystems() as $systemDto) {
                    if (! $matches($projectFilter, $systemDto->name)) {
                        continue;
                    }

                    $counts['projects_seen']++;

                    ['system' => $system, 'wasCreated' => $systemIsNew] = $upserter->upsertSystem(AzDoNormalizer::SOURCE_ID, $systemDto);
                    $counts[$systemIsNew ? 'systems_created' : 'systems_updated']++;

                    $hadAsset = $system->software_asset_id !== null;
                    $linker->linkSystemToAsset($system);
                    if (! $hadAsset && $system->refresh()->software_asset_id !== null) {
                        $counts['assets_created']++;
                    }

                    foreach ($source->fetchContainers($systemDto) as $containerDto) {
                        if (! $matches($repoFilter, $containerDto->name)) {
                            continue;
                        }

                        $counts['repos_seen']++;

                        ['container' => $container, 'wasCreated' => $containerIsNew] = $upserter->upsertContainer($system, $containerDto);
                        $counts[$containerIsNew ? 'containers_created' : 'containers_updated']++;

                        $hadMapping = $container->repositoryMappings()->exists();
                        $linker->ensureRepositoryMapping($container);
                        if (! $hadMapping && $container->repositoryMappings()->exists()) {
                            $counts['repository_mappings_created']++;
                        }
                    }
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], [
            ['Projects seen', $counts['projects_seen']],
            ['Software systems created', $counts['systems_created']],
            ['Software systems updated', $counts['systems_updated']],
            ['Software assets created', $counts['assets_created']],
            ['Repositories seen', $counts['repos_seen']],
            ['Security containers created', $counts['containers_created']],
            ['Security containers updated', $counts['containers_updated']],
            ['Repository mappings created', $counts['repository_mappings_created']],
        ]);

        return self::SUCCESS;
    },
)->purpose('Sync every Azure DevOps project and repository into SoftwareAsset/SoftwareSystem/SecurityContainer/RepositoryMapping rows, without touching alerts');

Artisan::command(
    'assets:reparse-attachments {--kind=}',
    function (AttachmentIngestionService $ingestion): int {
        $kindOption = $this->option('kind');
        $kind = is_string($kindOption) && $kindOption !== '' ? $kindOption : null;

        $query = Attachment::query()->when($kind !== null, fn ($q) => $q->where('kind', $kind));
        $count = 0;

        foreach ($query->cursor() as $attachment) {
            $ingestion->ingest($attachment);
            $count++;
        }

        $this->info(sprintf('Reparsed %d attachment(s)%s.', $count, $kind !== null ? " of kind '{$kind}'" : ''));

        return self::SUCCESS;
    },
)->purpose('Re-parse existing sbom/vulnerabilities/secrets attachments into SoftwareComponent/LocalFinding rows (e.g. after a parser fix, without re-scanning)');

Artisan::command(
    'dependencytrack:bootstrap {--base-url=} {--team=Automation} {--admin-username=admin} {--admin-password=admin} {--force} '
    . '{--trivy-base-url=} {--trivy-token=} {--trivy-token-file=}',
    function (DependencyTrackAdminClientFactory $adminClientFactory, DependencyTrackClientFactory $clientFactory, Vault $vault): int {
        $baseUrlOption = $this->option('base-url');
        $baseUrl = is_string($baseUrlOption) && $baseUrlOption !== ''
            ? $baseUrlOption
            : ($vault->get('dependencytrack.baseUrl', null, true) ?? 'http://dependencytrack-apiserver:8080');
        $team = (string) $this->option('team');
        $adminUsername = (string) $this->option('admin-username');
        $force = (bool) $this->option('force');

        $existingApiKey = $vault->get('dependencytrack.apiKey', null, true);

        if ($existingApiKey !== null && ! $force && $clientFactory->make($existingApiKey, $baseUrl)->ping()) {
            $vault->set('dependencytrack.baseUrl', null, $baseUrl);
            $this->info('Dependency-Track is already configured; nothing to do.');

            return self::SUCCESS;
        }

        $adminClient = $adminClientFactory->make($baseUrl);
        $adminPassword = $vault->get('dependencytrack.adminPassword', null, true) ?? (string) $this->option('admin-password');

        try {
            $token = $adminClient->login($adminUsername, $adminPassword);
        } catch (ClientException) {
            $this->error(sprintf(
                'Could not log in to Dependency-Track as "%s": invalid credentials. '
                . 'If the admin password was changed outside of AppSec Scout, pass --admin-password with the current value.',
                $adminUsername,
            ));

            return self::FAILURE;
        }

        if ($token === null) {
            $newPassword = Str::password(32);
            $adminClient->forceChangePassword($adminUsername, $adminPassword, $newPassword);
            $vault->set('dependencytrack.adminPassword', null, $newPassword);

            $token = $adminClient->login($adminUsername, $newPassword);

            if ($token === null) {
                $this->error('Logged in to Dependency-Track immediately after a forced password change, but login failed.');

                return self::FAILURE;
            }
        } else {
            // No forced rotation happened, so nothing else has persisted this password yet —
            // store it so a future re-run (e.g. --force to pick up new Trivy config) never
            // needs --admin-password supplied again.
            $vault->set('dependencytrack.adminPassword', null, $adminPassword);
        }

        $teamRecord = $adminClient->findOrCreateTeam($token, $team);

        foreach (['BOM_UPLOAD', 'PROJECT_CREATION_UPLOAD'] as $permission) {
            if (! in_array($permission, $teamRecord['permissions'], true)) {
                $adminClient->grantPermission($token, $permission, $teamRecord['uuid']);
            }
        }

        $existingPublicIds = $teamRecord['apiKeyPublicIds'];

        if ($existingPublicIds === []) {
            $newApiKey = $adminClient->createApiKey($token, $teamRecord['uuid']);
        } else {
            $newApiKey = $adminClient->regenerateApiKey($token, $existingPublicIds[0]);

            foreach (array_slice($existingPublicIds, 1) as $extraPublicId) {
                $adminClient->deleteApiKey($token, $extraPublicId);
            }
        }

        $vault->set('dependencytrack.apiKey', null, $newApiKey);
        $vault->set('dependencytrack.baseUrl', null, $baseUrl);

        $trivyTokenOption = $this->option('trivy-token');
        $trivyToken = is_string($trivyTokenOption) ? trim($trivyTokenOption) : '';

        if ($trivyToken === '') {
            $trivyTokenFileOption = $this->option('trivy-token-file');

            if (is_string($trivyTokenFileOption) && $trivyTokenFileOption !== '' && is_readable($trivyTokenFileOption)) {
                $trivyToken = trim((string) file_get_contents($trivyTokenFileOption));
            }
        }

        if ($trivyToken !== '') {
            $trivyBaseUrlOption = $this->option('trivy-base-url');
            $trivyBaseUrl = is_string($trivyBaseUrlOption) && $trivyBaseUrlOption !== ''
                ? $trivyBaseUrlOption
                : 'http://trivy-server:4954';

            $adminClient->setConfigProperty($token, 'scanner', 'trivy.enabled', 'true', 'BOOLEAN');
            $adminClient->setConfigProperty($token, 'scanner', 'trivy.base.url', $trivyBaseUrl, 'URL');
            $adminClient->setConfigProperty($token, 'scanner', 'trivy.api.token', $trivyToken, 'ENCRYPTEDSTRING');

            $this->info(sprintf('Configured Dependency-Track Trivy analyzer (base URL %s).', $trivyBaseUrl));
        } else {
            $this->warn('Skipping Dependency-Track Trivy analyzer configuration: no --trivy-token or readable --trivy-token-file was provided.');
        }

        $this->info(sprintf('Dependency-Track bootstrap complete (team "%s", base URL %s).', $team, $baseUrl));

        return self::SUCCESS;
    },
)->purpose('Provision the Dependency-Track automation team (permissions + API key), configure the Trivy analyzer, and store credentials in the vault; safe to re-run');

Artisan::command(
    'sbom:export-dependency-track {--api-key=} {--base-url=} {--container=} {--project-version=latest}',
    function (DependencyTrackClientFactory $clientFactory, Vault $vault): int {
        $apiKeyOption = $this->option('api-key');
        $apiKey = is_string($apiKeyOption) && $apiKeyOption !== ''
            ? $apiKeyOption
            : $vault->get('dependencytrack.apiKey', null, true);

        if ($apiKey === null) {
            $this->error('Dependency-Track API key is not configured. Run `php artisan dependencytrack:bootstrap` first, or pass --api-key.');

            return self::FAILURE;
        }

        $baseUrlOption = $this->option('base-url');
        $baseUrl = is_string($baseUrlOption) && $baseUrlOption !== ''
            ? $baseUrlOption
            : ($vault->get('dependencytrack.baseUrl', null, true) ?? 'http://dependencytrack-apiserver:8080');
        $containerId = $this->option('container');
        $projectVersion = (string) $this->option('project-version');

        $query = SecurityContainer::query()->whereHas('attachments', fn ($q) => $q->where('kind', 'sbom'));

        if (is_numeric($containerId)) {
            $query->whereKey((int) $containerId);
        }

        $containers = $query->get();

        if ($containers->isEmpty()) {
            $this->error('No security containers with a stored SBOM attachment were found.');

            return self::FAILURE;
        }

        $exporter = new DependencyTrackExporter($clientFactory->make($apiKey, $baseUrl));
        $rows = [];
        $failures = 0;

        foreach ($containers as $container) {
            try {
                $exporter->export($container, $projectVersion);
                $rows[] = [$container->name, 'Uploaded'];
            } catch (Throwable $exception) {
                $failures++;
                $rows[] = [$container->name, 'Failed: ' . $exception->getMessage()];
            }
        }

        $this->table(['Container', 'Result'], $rows);

        if ($failures > 0) {
            $this->error(sprintf('%d of %d upload(s) failed.', $failures, $containers->count()));

            return self::FAILURE;
        }

        $this->info(sprintf('Uploaded SBOM for %d container(s) to Dependency-Track.', $containers->count()));

        return self::SUCCESS;
    },
)->purpose("Push each security container's latest stored SBOM attachment to OWASP Dependency-Track as a project BOM upload");

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

Artisan::command('credentials:system:get {key}', function (SourceRegistry $sources, TrackerRegistry $trackers, Vault $vault): int {
    $key = (string) $this->argument('key');

    $knownKeys = [];

    foreach ($sources->all() as $source) {
        foreach ($source->credentialFields() as $field) {
            $knownKeys[] = $field->key;
        }
    }

    foreach ($trackers->all() as $tracker) {
        foreach ($tracker->credentialFields() as $field) {
            $knownKeys[] = $field->key;
        }
    }

    if (! in_array($key, $knownKeys, true)) {
        $this->error(sprintf('Unknown system credential key: %s', $key));

        return self::FAILURE;
    }

    $value = $vault->get($key, null, true);

    if ($value === null) {
        $this->error(sprintf('System credential "%s" is not configured.', $key));

        return self::FAILURE;
    }

    $this->output->write($value);

    return self::SUCCESS;
})->purpose('Print a single system credential value from the vault to stdout, for the ops/claude containers to reuse the credential already configured in appsec-scout instead of duplicating it in their own env files');

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
