<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class IntegrationSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $slug = 'admin/integrations';

    protected string $view = 'filament.pages.integration-settings-page';

    /** @var array<string, array{enabled: bool, fetch_interval_minutes: int, service_user_id: ?int}> */
    public array $settings = [];

    /** @var array<string, array{ok: bool, error: ?string}|null> */
    public array $testResults = [];

    public function mount(): void
    {
        $this->loadState();
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('admin.integrations') ?? false;
    }

    /** @return list<array<string, mixed>> */
    public function integrations(): array
    {
        $integrations = [];

        foreach ($this->integrationEntries() as $entry) {
            $key = $entry['key'];
            $setting = $this->settings[$key] ?? [
                'enabled' => false,
                'fetch_interval_minutes' => 30,
                'service_user_id' => null,
            ];

            $integrations[] = [
                'key' => $key,
                'kind' => $entry['kind'],
                'id' => $entry['id'],
                'display_name' => $entry['display_name'],
                'enabled' => $setting['enabled'],
                'fetch_interval_minutes' => $setting['fetch_interval_minutes'],
                'service_user_id' => $setting['service_user_id'],
                'last_synced_at' => $entry['setting']['last_synced_at'],
                'last_sync_status' => $entry['setting']['last_sync_status'],
                'last_sync_message' => $entry['setting']['last_sync_message'],
                'service_user_name' => $entry['setting']['model']?->serviceUser?->name,
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

        $interval = max(1, (int) ($state['fetch_interval_minutes'] ?? 30));
        $serviceUserId = $state['service_user_id'] ?? null;
        $serviceUserId = is_numeric($serviceUserId) ? (int) $serviceUserId : null;

        if ($serviceUserId !== null && ! User::query()->whereKey($serviceUserId)->exists()) {
            $this->addError("settings.{$key}.service_user_id", 'Choose a valid service user.');

            return;
        }

        $setting = app(IntegrationSettingsRepository::class)->update($entry['kind'], $entry['id'], [
            'enabled' => (bool) ($state['enabled'] ?? false),
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
        $ownerId = $this->selectedOwnerId($key);
        $keys = array_map(fn (string $credentialKey): string => $credentialKey, $entry['required_credential_keys']);

        $result = app(Vault::class)->runAsOwner($ownerId, function () use ($entry): object {
            return $entry['instance']->testConnection();
        }, true);

        app(Vault::class)->markTestedKeys($keys, $ownerId, (bool) $result->ok, $result->error);

        $this->testResults[$key] = [
            'ok' => (bool) $result->ok,
            'error' => $result->error,
        ];

        app(Recorder::class)->recordAdminAction('integration.connection_tested', [
            'integration_kind' => $entry['kind'],
            'integration_id' => $entry['id'],
            'service_user_id' => $ownerId,
            'outcome' => $result->ok ? 'success' : 'failure',
            'error' => $result->error,
        ]);

        Notification::make()
            ->title($result->ok ? 'Connection successful' : 'Connection failed')
            ->color($result->ok ? 'success' : 'danger')
            ->send();
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

    /** @return list<array{id: string, kind: string, key: string, display_name: string, instance: Source|Tracker, required_credential_keys: list<string>, setting: array<string, mixed>}> */
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

    /** @return array{id: string, kind: string, key: string, display_name: string, instance: Source|Tracker, required_credential_keys: list<string>, setting: array<string, mixed>} */
    private function integrationByKey(string $key): array
    {
        foreach ($this->integrationEntries() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        abort(404);
    }

    private function selectedOwnerId(string $key): ?int
    {
        $serviceUserId = $this->settings[$key]['service_user_id'] ?? null;

        return is_numeric($serviceUserId) ? (int) $serviceUserId : null;
    }
}
