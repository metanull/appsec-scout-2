<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\IntegrationSetting;
use App\Models\SyncRun;
use App\Models\User;
use App\Queue\QueueRuntimeInspector;
use App\Sources\Registry as SourceRegistry;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;
use App\Trackers\TrackerConfigRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
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
 */
class IntegrationSettingsPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array{source: list<string>, tracker: list<string>}|null */
    private ?array $queuedIntegrationIds = null;

    /**
     * @var array<string, array{enabled: bool, fetch_interval_minutes: int, service_user_id: int|string|null, jira_default_project?: ?string}>
     */
    public array $settings = [];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 22;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $slug = 'admin/integrations';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.integrations') : false;
    }

    public function mount(): void
    {
        $repository = app(IntegrationSettingsRepository::class);
        $sources = app(SourceRegistry::class)->all();
        $trackers = app(TrackerRegistry::class)->all();

        $repository->syncKnown(IntegrationSetting::KIND_SOURCE, array_map(fn ($s): string => $s->id(), $sources));
        $repository->syncKnown(IntegrationSetting::KIND_TRACKER, array_map(fn (Tracker $t): string => $t->id(), $trackers));

        $this->settings = IntegrationSetting::query()
            ->get()
            ->mapWithKeys(function (IntegrationSetting $setting): array {
                $key = $setting->integration_kind . ':' . $setting->integration_id;

                return [
                    $key => [
                        'enabled' => $setting->enabled,
                        'fetch_interval_minutes' => $setting->fetch_interval_minutes,
                        'service_user_id' => $setting->service_user_id,
                    ],
                ];
            })
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => IntegrationSetting::query()->orderBy('integration_kind')->orderBy('integration_id'))
            ->columns([
                TextColumn::make('integration_kind')
                    ->label('Kind')
                    ->badge()
                    ->color(fn (IntegrationSetting $record): string => $record->integration_kind === IntegrationSetting::KIND_SOURCE ? 'info' : 'warning')
                    ->sortable(),
                TextColumn::make('integration_id')
                    ->label('Integration')
                    ->formatStateUsing(fn (IntegrationSetting $record): string => $this->displayName($record))
                    ->searchable(),
                IconColumn::make('enabled')
                    ->label('Enabled')
                    ->boolean(),
                TextColumn::make('fetch_interval_minutes')
                    ->label('Interval (min)')
                    ->placeholder('-'),
                TextColumn::make('serviceUser.name')
                    ->label('Service user')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_sync_status')
                    ->label('Last sync status')
                    ->badge()
                    ->color(fn (IntegrationSetting $record): string => match ($this->resolveLastSyncStatus($record)) {
                        'success' => 'success',
                        'in_progress' => 'info',
                        'queued' => 'warning',
                        'failure', 'error' => 'danger',
                        default => 'gray',
                    })
                    ->state(fn (IntegrationSetting $record): string => $this->resolveLastSyncStatus($record))
                    ->placeholder('-'),
                TextColumn::make('last_sync_message')
                    ->label('Last message')
                    ->state(fn (IntegrationSetting $record): ?string => $this->statusMessageSummary($record->last_sync_message))
                    ->limit(80)
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->placeholder('-'),
            ])
            ->actions([
                Action::make('editSettings')
                    ->label('Edit settings')
                    ->icon('heroicon-o-pencil')
                    ->form(fn (IntegrationSetting $record): array => $this->buildEditForm($record))
                    ->fillForm(fn (IntegrationSetting $record): array => [
                        'enabled' => $record->enabled,
                        'fetch_interval_minutes' => $record->fetch_interval_minutes,
                        'service_user_id' => $record->service_user_id,
                        'jira_default_project' => $record->integration_id === 'jira'
                            ? app(TrackerConfigRepository::class)->getJiraDefaultProjectKey()
                            : null,
                    ])
                    ->action(fn (IntegrationSetting $record, array $data) => $this->persistIntegration($record, $data)),
                Action::make('testConnection')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->action(fn (IntegrationSetting $record) => $this->testIntegration($record)),
            ])
            ->emptyStateHeading('No integrations')
            ->paginated(false);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }

    private function displayName(IntegrationSetting $record): string
    {
        if ($record->integration_kind === IntegrationSetting::KIND_SOURCE) {
            foreach (app(SourceRegistry::class)->all() as $source) {
                if ($source->id() === $record->integration_id) {
                    return $source->displayName();
                }
            }
        } else {
            foreach (app(TrackerRegistry::class)->all() as $tracker) {
                if ($tracker->id() === $record->integration_id) {
                    return $tracker->displayName();
                }
            }
        }

        return $record->integration_id;
    }

    private function resolveLastSyncStatus(IntegrationSetting $record): string
    {
        $running = SyncRun::query()
            ->where('source_id', $record->integration_id)
            ->where('status', 'running')
            ->exists();

        if ($running) {
            return 'in_progress';
        }

        if ($this->isIntegrationQueued($record)) {
            return 'queued';
        }

        return $record->last_sync_status ?? '';
    }

    private function isIntegrationQueued(IntegrationSetting $record): bool
    {
        $queued = $this->getQueuedIntegrationIds();

        if ($record->integration_kind === IntegrationSetting::KIND_SOURCE) {
            return in_array($record->integration_id, $queued['source'], true);
        }

        if ($record->integration_kind === IntegrationSetting::KIND_TRACKER) {
            return in_array($record->integration_id, $queued['tracker'], true);
        }

        return false;
    }

    /** @return array{source: list<string>, tracker: list<string>} */
    private function getQueuedIntegrationIds(): array
    {
        if (! is_array($this->queuedIntegrationIds)) {
            $this->queuedIntegrationIds = app(QueueRuntimeInspector::class)->queuedIntegrationIds();
        }

        return $this->queuedIntegrationIds;
    }

    /** @return array<int, mixed> */
    private function buildEditForm(IntegrationSetting $record): array
    {
        $fields = [
            Toggle::make('enabled')->label('Enabled'),
            TextInput::make('fetch_interval_minutes')
                ->label('Fetch interval (minutes)')
                ->numeric()
                ->minValue(1)
                ->required(),
            Select::make('service_user_id')
                ->label('Service user')
                ->options(User::query()->orderBy('name')->pluck('name', 'id')->all())
                ->nullable()
                ->searchable(),
        ];

        if ($record->integration_id === 'jira' && $record->integration_kind === IntegrationSetting::KIND_TRACKER) {
            $fields[] = TextInput::make('jira_default_project')
                ->label('Jira default project key')
                ->nullable()
                ->maxLength(50);
        }

        return $fields;
    }

    /** @param array<string, mixed> $data */
    private function persistIntegration(IntegrationSetting $record, array $data): void
    {
        $serviceUserId = isset($data['service_user_id']) && is_numeric($data['service_user_id'])
            ? (int) $data['service_user_id']
            : null;

        $setting = app(IntegrationSettingsRepository::class)->update($record->integration_kind, $record->integration_id, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'fetch_interval_minutes' => max(1, (int) ($data['fetch_interval_minutes'] ?? 30)),
            'service_user_id' => $serviceUserId,
        ]);

        app(Recorder::class)->recordAdminAction('integration.settings_updated', [
            'integration_kind' => $setting->integration_kind,
            'integration_id' => $setting->integration_id,
            'enabled' => $setting->enabled,
            'fetch_interval_minutes' => $setting->fetch_interval_minutes,
            'service_user_id' => $setting->service_user_id,
        ]);

        $legacyKey = $setting->integration_kind . ':' . $setting->integration_id;
        $this->settings[$legacyKey] = [
            'enabled' => $setting->enabled,
            'fetch_interval_minutes' => $setting->fetch_interval_minutes,
            'service_user_id' => $setting->service_user_id,
        ];

        if ($record->integration_id === 'jira' && $record->integration_kind === IntegrationSetting::KIND_TRACKER) {
            $projectKey = isset($data['jira_default_project']) && is_string($data['jira_default_project'])
                ? trim($data['jira_default_project'])
                : null;
            app(TrackerConfigRepository::class)->setJiraDefaultProjectKey($projectKey !== '' ? $projectKey : null);
        }

        Notification::make()->title('Integration settings saved')->success()->send();
    }

    public function saveIntegration(string $target): void
    {
        [$kind, $id] = array_pad(explode(':', $target, 2), 2, null);

        if (! is_string($kind) || $kind === '' || ! is_string($id) || $id === '') {
            return;
        }

        $record = IntegrationSetting::query()
            ->where('integration_kind', $kind)
            ->where('integration_id', $id)
            ->first();

        if (! $record instanceof IntegrationSetting) {
            return;
        }

        $legacy = $this->settings[$target] ?? [];

        $this->persistIntegration($record, [
            'enabled' => (bool) ($legacy['enabled'] ?? $record->enabled),
            'fetch_interval_minutes' => (int) ($legacy['fetch_interval_minutes'] ?? $record->fetch_interval_minutes),
            'service_user_id' => $legacy['service_user_id'] ?? $record->service_user_id,
            'jira_default_project' => $legacy['jira_default_project'] ?? null,
        ]);
    }

    private function testIntegration(IntegrationSetting $record): void
    {
        $instance = null;

        if ($record->integration_kind === IntegrationSetting::KIND_SOURCE) {
            foreach (app(SourceRegistry::class)->all() as $source) {
                if ($source->id() === $record->integration_id) {
                    $instance = $source;
                    break;
                }
            }
        } else {
            foreach (app(TrackerRegistry::class)->all() as $tracker) {
                if ($tracker->id() === $record->integration_id) {
                    $instance = $tracker;
                    break;
                }
            }
        }

        if ($instance === null) {
            Notification::make()->title('Integration not found')->danger()->send();

            return;
        }

        $result = app(Vault::class)->runAsOwner(null, fn () => $instance->testConnection(), true);

        app(Recorder::class)->recordAdminAction('integration.connection_tested', [
            'integration_kind' => $record->integration_kind,
            'integration_id' => $record->integration_id,
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

        if (str_contains($message, "Data too long for column 'version_control_url'")) {
            return 'Data too long for version_control_url. See Error Logs for the full database error.';
        }

        return $message;
    }
}
