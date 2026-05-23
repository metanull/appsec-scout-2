<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\IntegrationSetting;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * @phpstan-type IntegrationState array{enabled: bool, fetch_interval_minutes: int, service_user_id: ?int}
 * @phpstan-type IntegrationTestResult array{ok: bool, error: ?string}
 * @phpstan-type IntegrationRepositoryState array{
 *   integration_kind: string,
 *   integration_id: string,
 *   enabled: bool,
 *   fetch_interval_minutes: int,
 *   service_user_id: ?int,
 *   last_synced_at: \Illuminate\Support\Carbon|null,
 *   last_sync_status: ?string,
 *   last_sync_message: ?string,
 *   model: \App\Models\IntegrationSetting|null
 * }
 * @phpstan-type IntegrationEntry array{
 *   id: string,
 *   kind: string,
 *   key: string,
 *   display_name: string,
 *   instance: \App\Sources\Contracts\Source|\App\Trackers\Contracts\Tracker,
 *   required_credential_keys: list<string>,
 *   setting: IntegrationRepositoryState
 * }
 */
class IntegrationSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $slug = 'admin/integrations';

    protected string $view = 'filament.pages.integration-settings-page';

    /** @var array<string, IntegrationState> */
    public array $settings = [];

    /** @var array<string, IntegrationTestResult|null> */
    public array $testResults = [];

    public function mount(): void
    {
        $this->loadState();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.integrations') : false;
    }

    /** @return list<array<string, mixed>> */
    public function integrations(): array
    {
        $integrations = [];

        foreach ($this->integrationEntries() as $entry) {
            $key = $entry['key'];
            /** @var IntegrationState $setting */
            $setting = $this->settings[$key] ?? [
                'enabled' => false,
                'fetch_interval_minutes' => 30,
                'service_user_id' => null,
            ];
            $runningSync = $entry['kind'] === IntegrationSetting::KIND_SOURCE
                ? SyncRun::query()
                    ->where('source_id', $entry['id'])
                    ->where('status', 'running')
                    ->latest('id')
                    ->first()
                : null;

            $integrations[] = [
                'key' => $key,
                'kind' => $entry['kind'],
                'id' => $entry['id'],
                'display_name' => $entry['display_name'],
                'enabled' => $setting['enabled'],
                'fetch_interval_minutes' => $setting['fetch_interval_minutes'],
                'service_user_id' => $setting['service_user_id'],
                'last_synced_at' => $entry['setting']['last_synced_at'],
                'sync_started_at' => $runningSync?->started_at,
                'last_sync_status' => $runningSync instanceof SyncRun ? 'in_progress' : $entry['setting']['last_sync_status'],
                'last_sync_message' => $entry['setting']['last_sync_message'],
                'service_user_name' => $entry['setting']['model']?->serviceUser instanceof User
                    ? $entry['setting']['model']->serviceUser->name
                    : null,
            ];
        }

        return $integrations;
    }

    /** @return array<int|string, string> */
    public function serviceUserOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function saveIntegration(string $key): void
    {
        $entry = $this->integrationByKey($key);
        $state = $this->settings[$key] ?? null;

        if (! is_array($state)) {
            abort(404);
        }

        $interval = max(1, $state['fetch_interval_minutes']);
        $serviceUserId = $state['service_user_id'] ?? null;
        $serviceUserId = is_numeric($serviceUserId) ? (int) $serviceUserId : null;

        if ($serviceUserId !== null && ! User::query()->whereKey($serviceUserId)->exists()) {
            $this->addError("settings.{$key}.service_user_id", 'Choose a valid service user.');

            return;
        }

        $setting = app(IntegrationSettingsRepository::class)->update($entry['kind'], $entry['id'], [
            'enabled' => $state['enabled'],
            'fetch_interval_minutes' => $interval,
            'service_user_id' => $serviceUserId,
        ]);

        app(Recorder::class)->recordAdminAction('integration.settings_updated', [
            'integration_kind' => $setting->integration_kind,
            'integration_id' => $setting->integration_id,
            'enabled' => $setting->enabled,
            'fetch_interval_minutes' => $setting->fetch_interval_minutes,
            'service_user_id' => $setting->service_user_id,
        ]);

        $this->loadState();

        Notification::make()->title('Integration settings saved')->success()->send();
    }

    public function testIntegration(string $key): void
    {
        $entry = $this->integrationByKey($key);
        $keys = array_map(fn (string $credentialKey): string => $credentialKey, $entry['required_credential_keys']);

        $result = app(Vault::class)->runAsOwner(null, function () use ($entry): object {
            return $entry['instance']->testConnection();
        }, true);

        app(Vault::class)->markTestedKeys($keys, null, (bool) $result->ok, $result->error);

        $this->testResults[$key] = [
            'ok' => (bool) $result->ok,
            'error' => $result->error,
        ];

        app(Recorder::class)->recordAdminAction('integration.connection_tested', [
            'integration_kind' => $entry['kind'],
            'integration_id' => $entry['id'],
            'service_user_id' => null,
            'outcome' => $result->ok ? 'success' : 'failure',
            'error' => $result->error,
        ]);

        Notification::make()
            ->title($result->ok ? 'Connection successful' : 'Connection failed')
            ->color($result->ok ? 'success' : 'danger')
            ->send();
    }

    public function statusMessageSummary(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        if (str_contains($message, 'Data too long for column')) {
            $column = Str::between($message, "Data too long for column '", "'");

            return $column !== ''
                ? "Data too long for {$column}. See Error Logs for the full database error."
                : 'Database value exceeded the column size. See Error Logs for the full error.';
        }

        $normalized = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        return Str::limit($normalized, 240);
    }

    private function loadState(): void
    {
        foreach ($this->integrationEntries() as $entry) {
            $key = $entry['key'];
            $setting = $entry['setting'];

            $this->settings[$key] = [
                'enabled' => $setting['enabled'],
                'fetch_interval_minutes' => $setting['fetch_interval_minutes'],
                'service_user_id' => $setting['service_user_id'],
            ];
            $this->testResults[$key] = $this->testResults[$key] ?? null;
        }
    }

    /** @return list<IntegrationEntry> */
    private function integrationEntries(): array
    {
        $repository = app(IntegrationSettingsRepository::class);
        $entries = [];

        $sources = app(SourceRegistry::class)->all();
        $repository->syncKnown(IntegrationSetting::KIND_SOURCE, array_map(fn (Source $source): string => $source->id(), $sources));

        foreach ($sources as $source) {
            $entries[] = [
                'id' => $source->id(),
                'kind' => IntegrationSetting::KIND_SOURCE,
                'key' => IntegrationSetting::KIND_SOURCE . ':' . $source->id(),
                'display_name' => $source->displayName(),
                'instance' => $source,
                'required_credential_keys' => $source->requiredCredentialKeys(),
                'setting' => $repository->get(IntegrationSetting::KIND_SOURCE, $source->id()),
            ];
        }

        $trackers = app(TrackerRegistry::class)->all();
        $repository->syncKnown(IntegrationSetting::KIND_TRACKER, array_map(fn (Tracker $tracker): string => $tracker->id(), $trackers));

        foreach ($trackers as $tracker) {
            $entries[] = [
                'id' => $tracker->id(),
                'kind' => IntegrationSetting::KIND_TRACKER,
                'key' => IntegrationSetting::KIND_TRACKER . ':' . $tracker->id(),
                'display_name' => $tracker->displayName(),
                'instance' => $tracker,
                'required_credential_keys' => $tracker->requiredCredentialKeys(),
                'setting' => $repository->get(IntegrationSetting::KIND_TRACKER, $tracker->id()),
            ];
        }

        usort($entries, fn (array $left, array $right): int => [$left['kind'], $left['display_name']] <=> [$right['kind'], $right['display_name']]);

        return $entries;
    }

    /** @return IntegrationEntry */
    private function integrationByKey(string $key): array
    {
        foreach ($this->integrationEntries() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        abort(404);
    }
}
