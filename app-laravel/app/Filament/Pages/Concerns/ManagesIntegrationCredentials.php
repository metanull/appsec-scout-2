<?php

namespace App\Filament\Pages\Concerns;

use App\Credentials\Credential;
use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\Registry as SourceControlRegistry;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

trait ManagesIntegrationCredentials
{
    use InteractsWithForms;

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
        $this->syncCredentialFormState();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->credentialSections());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testAllConfigured')
                ->label('Test all configured')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn () => $this->testAllConfiguredIntegrations()),
            Action::make('saveAllChanges')
                ->label('Save all changes')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->saveAllCredentials()),
        ];
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

        foreach (app(SourceControlRegistry::class)->all() as $sourceControl) {
            $integrations[] = $this->integrationDescriptor('source_control', $sourceControl->id(), $sourceControl->displayName(), $sourceControl->credentialFields());
        }

        return $integrations;
    }

    public function saveIntegration(string $integrationId): void
    {
        if (! $this->saveIntegrationCredentials($integrationId)) {
            return;
        }

        $this->loadCredentialState();
        $this->syncCredentialFormState();

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
        $this->syncCredentialFormState();

        Notification::make()
            ->title($saved > 0 ? "Saved credentials for {$saved} integration(s)" : 'No credentials to save')
            ->color($saved > 0 ? 'success' : 'info')
            ->send();
    }

    public function testIntegration(string $integrationId): void
    {
        if (! $this->saveIntegrationCredentials($integrationId)) {
            Notification::make()
                ->title('Could not test connection')
                ->body('Fix validation errors and try again.')
                ->warning()
                ->send();

            return;
        }

        $this->loadCredentialState();
        $this->syncCredentialFormState();

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

    /** @return array<int, Section> */
    private function credentialSections(): array
    {
        $sections = [];

        foreach ($this->integrations() as $integration) {
            $integrationId = $integration['id'];
            $actionKey = str_replace(['-', '.'], '_', $integrationId);

            $sectionComponents = array_map(
                fn (CredentialField $field): TextInput => $this->credentialInputComponent($field),
                $integration['credential_fields'],
            );

            $sectionComponents[] = TextInput::make("descriptions.{$integrationId}")
                ->label('Description')
                ->extraInputAttributes($this->passwordManagerIgnoreAttributes())
                ->columnSpanFull();

            $sections[] = Section::make($integration['display_name'])
                ->description(fn (): ?string => $this->integrationStatusDescription($integrationId))
                ->headerActions([
                    Action::make("test_{$actionKey}")
                        ->label('Test')
                        ->icon('heroicon-o-signal')
                        ->color('gray')
                        ->action(fn () => $this->testIntegration($integrationId)),
                ])
                ->schema([
                    Grid::make(2)->schema($sectionComponents),
                ]);
        }

        return $sections;
    }

    private function credentialInputComponent(CredentialField $field): TextInput
    {
        $stateKey = $field->stateKey();

        $component = TextInput::make("values.{$stateKey}")
            ->label($field->label)
            ->helperText($field->description)
            ->required($field->required)
            ->live()
            ->extraInputAttributes($this->passwordManagerIgnoreAttributes());

        if (! $field->isSecret) {
            return $component;
        }

        $replaceStatePath = "replace.{$stateKey}";

        return $component
            ->password()
            ->revealable()
            ->placeholder(fn (Get $get): ?string => $this->secretPlaceholder($stateKey, $get))
            ->disabled(fn (Get $get): bool => $this->isStoredSecretLocked($stateKey, $get))
            ->dehydrated(fn (Get $get): bool => ! $this->isStoredSecretLocked($stateKey, $get))
            ->suffixActions([
                Action::make("replace_{$stateKey}")
                    ->label('Replace')
                    ->button()
                    ->outlined()
                    ->size('xs')
                    ->color('gray')
                    ->visible(fn (Get $get): bool => ($this->hasStored[$stateKey] ?? false) && ! $get->boolean($replaceStatePath))
                    ->action(function () use ($stateKey): void {
                        $this->replace[$stateKey] = true;
                        $this->values[$stateKey] = '';
                    }),
                Action::make("cancel_replace_{$stateKey}")
                    ->label('Cancel')
                    ->button()
                    ->outlined()
                    ->size('xs')
                    ->color('gray')
                    ->visible(fn (Get $get): bool => ($this->hasStored[$stateKey] ?? false) && $get->boolean($replaceStatePath))
                    ->action(function () use ($stateKey): void {
                        $this->replace[$stateKey] = false;
                        $this->values[$stateKey] = '';
                    }),
            ]);
    }

    private function integrationStatusDescription(string $integrationId): ?string
    {
        $result = $this->testResults[$integrationId] ?? null;

        if (! is_array($result)) {
            return null;
        }

        return $result['ok'] ? 'Connected' : 'Connection failed';
    }

    /** @return array<string, string> */
    private function passwordManagerIgnoreAttributes(): array
    {
        return [
            'autocomplete' => 'off',
            'autocapitalize' => 'off',
            'autocorrect' => 'off',
            'spellcheck' => 'false',
            'data-lpignore' => 'true',
            'data-1p-ignore' => 'true',
            'data-bwignore' => 'true',
        ];
    }

    private function isStoredSecretLocked(string $stateKey, Get $get): bool
    {
        return ($this->hasStored[$stateKey] ?? false) && ! $get->boolean("replace.{$stateKey}");
    }

    private function secretPlaceholder(string $stateKey, Get $get): ?string
    {
        if (! ($this->hasStored[$stateKey] ?? false)) {
            return null;
        }

        if (! $get->boolean("replace.{$stateKey}")) {
            return 'Stored secret. Click Replace to edit.';
        }

        return 'Enter new value to replace stored secret';
    }

    private function syncCredentialFormState(): void
    {
        $form = $this->getForm('form');

        if (! $form instanceof Schema) {
            return;
        }

        $form->fill([
            'values' => $this->values,
            'descriptions' => $this->descriptions,
            'replace' => $this->replace,
        ]);
    }

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
                    $existingValue = $this->credentialValue($existing);

                    if ($existingValue === null) {
                        $this->addError("values.{$stateKey}", 'Stored credential cannot be decrypted. Click Replace and save a new value.');

                        return false;
                    }

                    if ($description !== $existing->description) {
                        app(Vault::class)->set($field->key, $ownerId, $existingValue, $description);
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

                $existingValue = $this->credentialValue($existing);

                if ($existing instanceof Credential && $existingValue !== null && $value === $existingValue && $description === $existing->description) {
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
                    $credentialValue = $this->credentialValue($credential);

                    if ($credentialValue !== null) {
                        $this->descriptions[$integration['id']] = $credential->description ?? '';
                        $this->values[$stateKey] = $field->isSecret ? '' : $credentialValue;

                        continue;
                    }

                    $this->hasStored[$stateKey] = false;
                    $this->values[$stateKey] = '';
                } else {
                    $this->values[$stateKey] = '';
                }
            }
        }
    }

    /** @return array{id: string, type: string, display_name: string, instance: Source|Tracker|SourceControlProvider, credential_fields: list<CredentialField>} */
    private function integrationById(string $integrationId): array
    {
        foreach ($this->integrationEntries() as $integration) {
            if ($integration['id'] === $integrationId) {
                return $integration;
            }
        }

        throw new \RuntimeException("Unknown integration [{$integrationId}].");
    }

    /** @return list<array{id: string, type: string, display_name: string, instance: Source|Tracker|SourceControlProvider, credential_fields: list<CredentialField>}> */
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

        foreach (app(SourceControlRegistry::class)->all() as $sourceControl) {
            $integrations[] = array_merge(
                $this->integrationDescriptor('source_control', $sourceControl->id(), $sourceControl->displayName(), $sourceControl->credentialFields()),
                ['instance' => $sourceControl],
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

    private function credentialValue(?Credential $credential): ?string
    {
        if (! $credential instanceof Credential) {
            return null;
        }

        $encrypted = $credential->getRawOriginal('value');

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decrypt($encrypted, false);

            return is_string($decrypted) ? $decrypted : null;
        } catch (DecryptException) {
            return null;
        }
    }
}
