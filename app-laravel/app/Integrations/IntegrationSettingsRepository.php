<?php

namespace App\Integrations;

use App\Models\IntegrationSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * @phpstan-type IntegrationSettingsResult array{
 *   integration_kind: string,
 *   integration_id: string,
 *   enabled: bool,
 *   fetch_interval_minutes: int,
 *   last_synced_at: ?Carbon,
 *   last_sync_status: ?string,
 *   last_sync_message: ?string,
 *   model: ?IntegrationSetting
 * }
 */
final class IntegrationSettingsRepository
{
    /**
     * @return IntegrationSettingsResult
     */
    public function get(string $kind, string $integrationId): array
    {
        $defaults = $this->defaults($integrationId);

        if (! $this->tableExists()) {
            return [
                'integration_kind' => $kind,
                'integration_id' => $integrationId,
                'enabled' => $defaults['enabled'],
                'fetch_interval_minutes' => $defaults['fetch_interval_minutes'],
                'last_synced_at' => null,
                'last_sync_status' => null,
                'last_sync_message' => null,
                'model' => null,
            ];
        }

        $setting = IntegrationSetting::query()->firstOrCreate(
            [
                'integration_kind' => $kind,
                'integration_id' => $integrationId,
            ],
            $defaults,
        );

        $lastSyncedAt = $setting->last_synced_at;

        if (is_string($lastSyncedAt)) {
            $lastSyncedAt = Carbon::parse($lastSyncedAt);
        }

        return [
            'integration_kind' => $setting->integration_kind,
            'integration_id' => $setting->integration_id,
            'enabled' => (bool) $setting->enabled,
            'fetch_interval_minutes' => max(1, (int) $setting->fetch_interval_minutes),
            'last_synced_at' => $lastSyncedAt,
            'last_sync_status' => $setting->last_sync_status,
            'last_sync_message' => $setting->last_sync_message,
            'model' => $setting,
        ];
    }

    public function isEnabled(string $kind, string $integrationId): bool
    {
        return $this->get($kind, $integrationId)['enabled'];
    }

    public function isDue(string $kind, string $integrationId): bool
    {
        $setting = $this->get($kind, $integrationId);

        if (! $setting['enabled']) {
            return false;
        }

        if ($setting['last_synced_at'] === null) {
            return true;
        }

        return $setting['last_synced_at']->lte(now()->subMinutes($setting['fetch_interval_minutes']));
    }

    /** @param array<string, bool|int|string|Carbon|null> $attributes */
    public function update(string $kind, string $integrationId, array $attributes): IntegrationSetting
    {
        if (! $this->tableExists()) {
            throw new RuntimeException('The integration_settings table has not been migrated yet.');
        }

        $setting = IntegrationSetting::query()->firstOrCreate(
            [
                'integration_kind' => $kind,
                'integration_id' => $integrationId,
            ],
            $this->defaults($integrationId),
        );

        $setting->fill($attributes);
        $setting->save();

        return $setting->fresh() ?? $setting;
    }

    public function markSyncResult(string $kind, string $integrationId, bool $ok, ?string $message = null): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $this->update($kind, $integrationId, [
            'last_synced_at' => now(),
            'last_sync_status' => $ok ? 'success' : 'failure',
            'last_sync_message' => $message,
        ]);
    }

    /** @param iterable<string> $integrationIds */
    public function syncKnown(string $kind, iterable $integrationIds): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $knownIds = [];

        foreach ($integrationIds as $integrationId) {
            if ($integrationId === '') {
                continue;
            }

            $knownIds[] = $integrationId;

            $this->get($kind, $integrationId);
        }

        if ($knownIds !== []) {
            IntegrationSetting::query()
                ->where('integration_kind', $kind)
                ->whereNotIn('integration_id', $knownIds)
                ->delete();
        }
    }

    /** @return array{enabled: bool, fetch_interval_minutes: int} */
    private function defaults(string $integrationId): array
    {
        return [
            'enabled' => (bool) config("integration_settings.{$integrationId}.enabled", false),
            'fetch_interval_minutes' => max(1, (int) config("integration_settings.{$integrationId}.interval_minutes", 30)),
        ];
    }

    private function tableExists(): bool
    {
        return Schema::hasTable('integration_settings');
    }
}
