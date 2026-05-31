<?php

namespace App\Filament\Pages\Concerns;

use App\Credentials\Credential;
use App\Credentials\CredentialField;
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

    /** @return list<array{id: string, type: string, display_name: string, credential_fields: list<CredentialField>}> */
    public function integrations(): array
    {
        $integrations = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            $integrations[] = $this->integrationDescriptor('source', $source->id(), $source->displayName(), $source->credentialFields());
        }

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $integrations[] = $this->integrationDescriptor('tracker', $tracker->id(), $tracker->displayName(), $tracker->credentialFields());
        }

        return $integrations;
    }

    public function saveIntegration(string $integrationId): void
    {
        if (! $this->saveIntegrationCredentials($integrationId)) {
            return;
        }

        $this->loadCredentialState();

        Notification::make()->title('Credentials saved')->success()->send();
    }

    public function saveAllCredentials(): void
    {
        $saved = 0;

        foreach ($this->integrations() as $integration) {
            if ($this->saveIntegrationCredentials($integration['id'])) {
                $saved++;
            }
        }

        $this->loadCredentialState();

        Notification::make()
            ->title($saved > 0 ? "Saved credentials for {$saved} integration(s)" : 'No credentials to save')
            ->color($saved > 0 ? 'success' : 'info')
            ->send();
    }

    public function testIntegration(string $integrationId): void
    {
        $this->runIntegrationTest($integrationId);

        /** @var array{ok: bool, error: ?string} $result */
        $result = $this->testResults[$integrationId];

        Notification::make()
            ->title($result['ok'] ? 'Connection successful' : 'Connection failed')
            ->color($result['ok'] ? 'success' : 'danger')
            ->send();
    }

    public function testAllConfiguredIntegrations(): void
    {
        $ownerId = $this->credentialOwnerId();
        $tested = 0;

        foreach ($this->integrationEntries() as $integration) {
            $allStored = count(array_filter(
                $integration['credential_fields'],
                fn (CredentialField $field): bool => $this->credential($field->key, $ownerId) instanceof Credential,
            )) === count($integration['credential_fields']);

            if (! $allStored) {
                continue;
            }

            $this->runIntegrationTest($integration['id']);
            $tested++;
        }

        Notification::make()
            ->title($tested > 0 ? "Tested {$tested} integration(s)" : 'No fully configured integrations to test')
            ->color($tested > 0 ? 'info' : 'warning')
            ->send();
    }

    abstract protected function credentialOwnerId(): ?int;

    private function saveIntegrationCredentials(string $integrationId): bool
    {
        $integration = $this->integrationById($integrationId);
        $ownerId = $this->credentialOwnerId();
        $description = $this->normalizedDescription($integrationId);

        $requiredFieldStates = [];
        $hasAnyRequiredIntent = false;

        foreach ($integration['credential_fields'] as $field) {
            if (! $field->required) {
                continue;
            }

            $existing = $this->credential($field->key, $ownerId);
            $stateKey = $field->stateKey();
            $shouldReplace = $this->replace[$stateKey] ?? false;
            $value = trim((string) ($this->values[$stateKey] ?? ''));

            $isSatisfied = false;

            if ($value !== '') {
                $isSatisfied = true;
            } elseif ($existing instanceof Credential) {
                $isSatisfied = ! ($field->isSecret && $shouldReplace);
            }

            if ($value !== '' || $shouldReplace) {
                $hasAnyRequiredIntent = true;
            }

            $requiredFieldStates[] = [
                'state_key' => $stateKey,
                'is_satisfied' => $isSatisfied,
            ];
        }

        $hasAnySatisfiedRequired = count(array_filter(
            $requiredFieldStates,
            fn (array $fieldState): bool => $fieldState['is_satisfied'],
        )) > 0;

        if ($hasAnyRequiredIntent) {
            foreach ($requiredFieldStates as $fieldState) {
                if ($fieldState['is_satisfied']) {
                    continue;
                }

                $this->addError("values.{$fieldState['state_key']}", 'This field is required.');

                return false;
            }
        }

        foreach ($integration['credential_fields'] as $field) {
            $existing = $this->credential($field->key, $ownerId);
            $stateKey = $field->stateKey();
            $shouldReplace = $this->replace[$stateKey] ?? false;
            $value = trim((string) ($this->values[$stateKey] ?? ''));

            if ($field->isSecret) {
                if ($existing instanceof Credential && ! $shouldReplace) {
                    if ($description !== $existing->description) {
                        app(Vault::class)->set($field->key, $ownerId, $existing->value, $description);
                    }

                    continue;
                }

                if ($value === '') {
                    if ($field->required && $hasAnyRequiredIntent) {
                        $this->addError("values.{$stateKey}", 'Enter a replacement value before saving.');

                        return false;
                    }

                    continue;
                }

                app(Vault::class)->set($field->key, $ownerId, $value, $description);
            } else {
                if ($value === '' && ! $field->required) {
                    continue;
                }

                if ($field->required && $value === '' && ! $hasAnyRequiredIntent) {
                    continue;
                }

                if ($field->required && $value === '') {
                    $this->addError("values.{$stateKey}", 'This field is required.');

                    return false;
                }

                if ($existing instanceof Credential && $value === $existing->value && $description === $existing->description) {
                    continue;
                }

                app(Vault::class)->set($field->key, $ownerId, $value, $description);
            }
        }

        return true;
    }

    private function runIntegrationTest(string $integrationId): void
    {
        $integration = $this->integrationById($integrationId);
        $ownerId = $this->credentialOwnerId();
        $keys = array_map(fn (CredentialField $field): string => $field->key, $integration['credential_fields']);

        $result = app(Vault::class)->runAsOwner($ownerId, function () use ($integration): object {
            return $integration['instance']->testConnection();
        });

        app(Vault::class)->markTestedKeys($keys, $ownerId, (bool) $result->ok, $result->error);

        $this->testResults[$integrationId] = [
            'ok' => (bool) $result->ok,
            'error' => $result->error,
        ];
    }

    private function loadCredentialState(): void
    {
        $ownerId = $this->credentialOwnerId();

        foreach ($this->integrations() as $integration) {
            $this->descriptions[$integration['id']] = '';

            foreach ($integration['credential_fields'] as $field) {
                $credential = $this->credential($field->key, $ownerId);
                $stateKey = $field->stateKey();

                $this->replace[$stateKey] = false;
                $this->hasStored[$stateKey] = $credential instanceof Credential;

                if ($credential instanceof Credential) {
                    $this->descriptions[$integration['id']] = $credential->description ?? '';
                    $this->values[$stateKey] = $field->isSecret ? '' : $credential->value;
                } else {
                    $this->values[$stateKey] = '';
                }
            }
        }
    }

    /** @return array{id: string, type: string, display_name: string, instance: Source|Tracker, credential_fields: list<CredentialField>} */
    private function integrationById(string $integrationId): array
    {
        foreach ($this->integrationEntries() as $integration) {
            if ($integration['id'] === $integrationId) {
                return $integration;
            }
        }

        throw new \RuntimeException("Unknown integration [{$integrationId}].");
    }

    /** @return list<array{id: string, type: string, display_name: string, instance: Source|Tracker, credential_fields: list<CredentialField>}> */
    private function integrationEntries(): array
    {
        $integrations = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            $integrations[] = array_merge(
                $this->integrationDescriptor('source', $source->id(), $source->displayName(), $source->credentialFields()),
                ['instance' => $source],
            );
        }

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $integrations[] = array_merge(
                $this->integrationDescriptor('tracker', $tracker->id(), $tracker->displayName(), $tracker->credentialFields()),
                ['instance' => $tracker],
            );
        }

        return $integrations;
    }

    /**
     * @param  list<CredentialField>  $credentialFields
     * @return array{id: string, type: string, display_name: string, credential_fields: list<CredentialField>}
     */
    private function integrationDescriptor(string $type, string $id, string $displayName, array $credentialFields): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'display_name' => $displayName,
            'credential_fields' => $credentialFields,
        ];
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
