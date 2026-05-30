<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\IntegrationSetting;
use App\Models\SyncRun;
use App\Models\User;
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
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup;
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 22;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $slug = 'admin/integrations';

    protected string $view = 'filament.pages.integration-settings-page';

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
                    ->color(fn (IntegrationSetting $record): string => match ($record->last_sync_status) {
                        'success' => 'success',
                        'in_progress' => 'info',
                        'error' => 'danger',
                        default => 'gray',
                    })
                    ->state(fn (IntegrationSetting $record): string => $this->resolveLastSyncStatus($record))
                    ->placeholder('-'),
                TextColumn::make('last_sync_message')
                    ->label('Last message')
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
                ActionGroup::make([
                    TableAction::make('editSettings')
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
                        ->action(fn (IntegrationSetting $record, array $data) => $this->saveIntegration($record, $data)),
                    TableAction::make('testConnection')
                        ->label('Test connection')
                        ->icon('heroicon-o-signal')
                        ->color('gray')
                        ->action(fn (IntegrationSetting $record) => $this->testIntegration($record)),
                ]),
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

        return $record->last_sync_status ?? '';
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
    private function saveIntegration(IntegrationSetting $record, array $data): void
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

        if ($record->integration_id === 'jira' && $record->integration_kind === IntegrationSetting::KIND_TRACKER) {
            $projectKey = isset($data['jira_default_project']) && is_string($data['jira_default_project'])
                ? trim($data['jira_default_project'])
                : null;
            app(TrackerConfigRepository::class)->setJiraDefaultProjectKey($projectKey !== '' ? $projectKey : null);
        }

        Notification::make()->title('Integration settings saved')->success()->send();
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
}
