<?php

namespace App\Filament\Pages\Concerns;

use App\Credentials\Credential;
use App\Credentials\Vault;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Notifications\Notification;

trait ManagesIntegrationCredentials
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, string> */
    public array $descriptions = [];

    /** @var array<string, bool> */
    public array $replace = [];

    /** @var array<string, bool> */
    public array $hasStored = [];

    /** @var array<string, array{ok: bool, error: ?string}|null> */
    public array $testResults = [];

    public function mountManagesIntegrationCredentials(): void
    {
        $this->loadCredentialState();
    }

    /** @return list<array{id: string, type: string, display_name: string, required_credential_keys: list<array{key: string, state_key: string, is_secret: bool}>}> */
    public function integrations(): array
    {
        $integrations = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            $integrations[] = $this->integrationDescriptor('source', $source->id(), $source->displayName(), $source->requiredCredentialKeys());
        }

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $integrations[] = $this->integrationDescriptor('tracker', $tracker->id(), $tracker->displayName(), $tracker->requiredCredentialKeys());
        }

        return $integrations;
    }

    public function saveIntegration(string $integrationId): void
    {
        $integration = $this->integrationById($integrationId);
        $ownerId = $this->credentialOwnerId();
        $description = $this->normalizedDescription($integrationId);

        foreach ($integration['required_credential_keys'] as $field) {
            $existing = $this->credential($field['key'], $ownerId);
            $stateKey = $field['state_key'];
            $shouldReplace = $this->replace[$stateKey] ?? false;
            $value = trim((string) ($this->values[$stateKey] ?? ''));

            if ($existing instanceof Credential && ! $shouldReplace && $description === $existing->description) {
                continue;
            }

            if ($existing instanceof Credential && ! $shouldReplace) {
                app(Vault::class)->set($field['key'], $ownerId, $existing->value, $description);

                continue;
            }

            if ($value === '') {
                $this->addError("values.{$stateKey}", 'Enter a replacement value before saving.');

                return;
            }

            app(Vault::class)->set($field['key'], $ownerId, $value, $description);
        }

        $this->loadCredentialState();

        Notification::make()->title('Credentials saved')->success()->send();
    }

    public function testIntegration(string $integrationId): void
    {
        $integration = $this->integrationById($integrationId);
        $ownerId = $this->credentialOwnerId();
        $keys = array_map(fn (array $field): string => $field['key'], $integration['required_credential_keys']);

        $result = app(Vault::class)->runAsOwner($ownerId, function () use ($integration): object {
            return $integration['instance']->testConnection();
        });

        app(Vault::class)->markTestedKeys($keys, $ownerId, (bool) $result->ok, $result->error);

        $this->testResults[$integrationId] = [
            'ok' => (bool) $result->ok,
            'error' => $result->error,
        ];

        Notification::make()
            ->title($result->ok ? 'Connection successful' : 'Connection failed')
            ->color($result->ok ? 'success' : 'danger')
            ->send();
    }

    abstract protected function credentialOwnerId(): ?int;

    private function loadCredentialState(): void
    {
        $ownerId = $this->credentialOwnerId();

        foreach ($this->integrations() as $integration) {
            $this->descriptions[$integration['id']] = '';

            foreach ($integration['required_credential_keys'] as $field) {
                $credential = $this->credential($field['key'], $ownerId);
                $stateKey = $field['state_key'];

                $this->values[$stateKey] = '';
                $this->replace[$stateKey] = false;
                $this->hasStored[$stateKey] = $credential instanceof Credential;

                if ($credential instanceof Credential) {
                    $this->descriptions[$integration['id']] = $credential->description ?? '';
                }
            }
        }
    }

    /** @return array{id: string, type: string, display_name: string, instance: Source|Tracker, required_credential_keys: list<array{key: string, state_key: string, is_secret: bool}>} */
    private function integrationById(string $integrationId): array
    {
        foreach ($this->integrationEntries() as $integration) {
            if ($integration['id'] === $integrationId) {
                return $integration;
            }
        }

        throw new \RuntimeException("Unknown integration [{$integrationId}].");
    }

    /** @return list<array{id: string, type: string, display_name: string, instance: Source|Tracker, required_credential_keys: list<array{key: string, state_key: string, is_secret: bool}>}> */
    private function integrationEntries(): array
    {
        $integrations = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            $integrations[] = array_merge(
                $this->integrationDescriptor('source', $source->id(), $source->displayName(), $source->requiredCredentialKeys()),
                ['instance' => $source],
            );
        }

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $integrations[] = array_merge(
                $this->integrationDescriptor('tracker', $tracker->id(), $tracker->displayName(), $tracker->requiredCredentialKeys()),
                ['instance' => $tracker],
            );
        }

        return $integrations;
    }

    /** @param list<string> $requiredCredentialKeys
     * @return array{id: string, type: string, display_name: string, required_credential_keys: list<array{key: string, state_key: string, is_secret: bool}>}
     */
    private function integrationDescriptor(string $type, string $id, string $displayName, array $requiredCredentialKeys): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'display_name' => $displayName,
            'required_credential_keys' => array_map(fn (string $key): array => [
                'key' => $key,
                'state_key' => str_replace(['.', '-'], '_', $key),
                'is_secret' => $this->isSecretKey($key),
            ], $requiredCredentialKeys),
        ];
    }

    private function isSecretKey(string $key): bool
    {
        return str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'pat')
            || str_contains($key, 'key');
    }

    private function normalizedDescription(string $integrationId): ?string
    {
        $description = trim((string) ($this->descriptions[$integrationId] ?? ''));

        return $description !== '' ? $description : null;
    }

    private function credential(string $key, ?int $ownerId): ?Credential
    {
        return Credential::query()
            ->where('integration_key', $key)
            ->when($ownerId === null, fn ($query) => $query->whereNull('owner_user_id'), fn ($query) => $query->where('owner_user_id', $ownerId))
            ->first();
    }
}
