<?php

namespace App\Filament\Resources;

use App\Audit\Recorder;
use App\Filament\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Filament\Resources\FailedJobResource\Pages\ViewFailedJob;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.queue') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Failed job')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('failed_at')
                            ->label('Failed at')
                            ->dateTime('d M Y H:i:s'),
                        TextEntry::make('queue')
                            ->badge(),
                        TextEntry::make('_job')
                            ->label('Job')
                            ->state(fn (FailedJob $record): string => self::jobName($record->payload)),
                        TextEntry::make('_source_tracker')
                            ->label('Source / Tracker')
                            ->state(fn (FailedJob $record): string => self::sourceOrTracker($record->payload))
                            ->placeholder('-'),
                    ]),
                ]),

            Section::make('Exception')
                ->schema([
                    TextEntry::make('_exception')
                        ->label('')
                        ->state(fn (FailedJob $record): string => self::redactString($record->exception))
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ]),

            Section::make('Payload')
                ->collapsible()
                ->schema([
                    TextEntry::make('_payload')
                        ->label('')
                        ->state(fn (FailedJob $record): string => self::payloadFull($record->payload))
                        ->fontFamily('mono')
                        ->copyable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('failed_at')
                    ->label('Failed at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('queue')
                    ->badge(),
                TextColumn::make('job')
                    ->label('Job')
                    ->getStateUsing(fn (FailedJob $record): string => self::jobName($record->payload))
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'Unknown job')
                    ->wrap()
                    ->placeholder('Unknown job')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('payload LIKE ?', ["%{$search}%"])),
                TextColumn::make('exception_summary')
                    ->label('Exception')
                    ->getStateUsing(fn (FailedJob $record): string => self::exceptionPreview($record->exception))
                    ->wrap()
                    ->limit(200),
                TextColumn::make('source_tracker')
                    ->label('Source / Tracker')
                    ->getStateUsing(fn (FailedJob $record): string => self::sourceOrTracker($record->payload)),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->options(fn (): array => DB::table('failed_jobs')->distinct()->pluck('queue', 'queue')->all()),
                Filter::make('job_class')
                    ->form([TextInput::make('job_class')->label('Job class')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['job_class'] ?? null,
                        fn (Builder $q, string $v) => $q->whereRaw('payload LIKE ?', ["%{$v}%"]),
                    )),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->action(fn (FailedJob $record) => self::retryFailedJob($record->uuid)),
                Action::make('forget')
                    ->label('Forget')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => self::forgetFailedJob($record->uuid)),
            ])
            ->defaultSort('failed_at', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateDescription('No failed jobs recorded.')
            ->recordUrl(fn (FailedJob $record): string => FailedJobResource::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailedJobs::route('/'),
            'view' => ViewFailedJob::route('/{record}'),
        ];
    }

    public static function retryFailedJob(string $failedJobUuid): void
    {
        /** @var object{connection: string, payload: string, queue: string}|null $failedJob */
        $failedJob = app('queue.failer')->find($failedJobUuid);

        if ($failedJob === null) {
            Notification::make()->title('Failed job not found')->warning()->send();

            return;
        }

        app('queue')->connection($failedJob->connection)->pushRaw($failedJob->payload, $failedJob->queue);
        app('queue.failer')->forget($failedJobUuid);

        app(Recorder::class)->recordAdminAction('operations.retry_failed_job', ['failed_job_uuid' => $failedJobUuid]);

        Notification::make()->title('Failed job retried')->success()->send();
    }

    public static function forgetFailedJob(string $failedJobUuid): void
    {
        app('queue.failer')->forget($failedJobUuid);

        app(Recorder::class)->recordAdminAction('operations.forget_failed_job', ['failed_job_uuid' => $failedJobUuid]);

        Notification::make()->title('Failed job removed')->success()->send();
    }

    public static function payloadFull(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            return json_encode(self::redactArray($decoded), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[payload unavailable]';
        }

        return self::redactString($payload);
    }

    public static function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return 'Unknown job';
        }

        $displayName = $decoded['displayName'] ?? null;

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        $commandName = data_get($decoded, 'data.commandName');

        return is_string($commandName) && $commandName !== '' ? $commandName : 'Unknown job';
    }

    public static function exceptionPreview(string $exception): string
    {
        if (str_contains($exception, 'Data too long for column')) {
            $column = Str::between($exception, "Data too long for column '", "'");

            return $column !== ''
                ? "Database value exceeded security_events.{$column}. Run migrations, then retry or forget this failed job."
                : 'Database value exceeded a column size. Run migrations, then retry or forget this failed job.';
        }

        return Str::limit(self::redactString($exception), 1000);
    }

    public static function sourceOrTracker(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return '';
        }

        $sourceId = data_get($decoded, 'data.command.sourceId')
            ?? data_get($decoded, 'data.command.source_id')
            ?? data_get($decoded, 'data.sourceId')
            ?? null;

        if (is_string($sourceId) && $sourceId !== '') {
            return "source:{$sourceId}";
        }

        $trackerId = data_get($decoded, 'data.command.trackerId')
            ?? data_get($decoded, 'data.command.tracker_id')
            ?? data_get($decoded, 'data.trackerId')
            ?? null;

        if (is_string($trackerId) && $trackerId !== '') {
            return "tracker:{$trackerId}";
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = self::redactArray($value);

                continue;
            }

            if (is_scalar($value) && self::isSensitiveKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_string($value) ? self::redactString($value) : $value;
        }

        return $redacted;
    }

    private static function redactString(string $value): string
    {
        return (string) preg_replace(
            '/((token|secret|password|api[_-]?key|pat|authorization)[^=:\"]*[=:\"]\s*)([^\s\",}]+)/i',
            '$1[redacted]',
            $value,
        );
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'pat')
            || str_contains($normalized, 'authorization');
    }
}
