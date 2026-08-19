<?php

namespace App\Filament\Resources;

use App\Audit\Recorder;
use App\Filament\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Filament\Resources\FailedJobResource\Pages\ViewFailedJob;
use App\Filament\Support\DateRangeFilters;
use App\Filament\Support\JobPayloadInspector;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\CodeEntry;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Phiki\Grammar\Grammar;

class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Failed Jobs';

    public static function getNavigationBadge(): ?string
    {
        $count = DB::table('failed_jobs')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

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
                        TextEntry::make('_source_tracker')
                            ->label('Source / Tracker')
                            ->state(fn (FailedJob $record): ?string => JobPayloadInspector::sourceOrTracker($record->payload))
                            ->placeholder('-'),
                        TextEntry::make('_repository')
                            ->label('Repository')
                            ->state(fn (FailedJob $record): ?string => self::repositoryLabel($record->payload))
                            ->placeholder('-'),
                    ]),
                    TextEntry::make('_job')
                        ->label('Job')
                        ->state(fn (FailedJob $record): string => JobPayloadInspector::jobName($record->payload))
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ]),

            Section::make('Exception')
                ->schema([
                    TextEntry::make('_exception')
                        ->label('')
                        ->state(fn (FailedJob $record): string => $record->exception)
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ]),

            Section::make('Payload')
                ->collapsible()
                ->schema([
                    CodeEntry::make('_payload')
                        ->label('')
                        ->state(fn (FailedJob $record): string => self::payloadFull($record->payload))
                        ->grammar(Grammar::Json)
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
                    ->getStateUsing(fn (FailedJob $record): string => JobPayloadInspector::jobName($record->payload))
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'Unknown job')
                    ->wrap()
                    ->placeholder('Unknown job')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('payload LIKE ?', ["%{$search}%"])),
                TextColumn::make('exception_summary')
                    ->label('Exception')
                    ->getStateUsing(fn (FailedJob $record): string => JobPayloadInspector::exceptionPreview($record->exception))
                    ->wrap()
                    ->limit(200)
                    ->placeholder('-'),
                TextColumn::make('source_tracker')
                    ->label('Source / Tracker')
                    ->getStateUsing(fn (FailedJob $record): ?string => JobPayloadInspector::sourceOrTracker($record->payload))
                    ->placeholder('-'),
                TextColumn::make('repository')
                    ->label('Repository')
                    ->getStateUsing(fn (FailedJob $record): ?string => self::repositoryLabel($record->payload))
                    ->placeholder('-')
                    ->toggleable(),
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
                ...DateRangeFilters::for('failed_at'),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->action(fn (FailedJob $record) => self::notifyRetryResult(self::retryFailedJob($record->uuid))),
                Action::make('forget')
                    ->label('Forget')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => self::notifyForgetResult(self::forgetFailedJob($record->uuid))),
            ])
            ->bulkActions([
                BulkAction::make('retrySelected')
                    ->label('Retry selected')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalHeading('Retry selected failed jobs')
                    ->modalDescription('Each selected job is re-queued and removed from the failed jobs list.')
                    ->action(function (Collection $records): void {
                        /** @var Collection<int, FailedJob> $records */
                        $succeeded = 0;
                        foreach ($records as $record) {
                            if (self::retryFailedJob($record->uuid)) {
                                $succeeded++;
                            }
                        }

                        self::notifyBulkResult('retried', $succeeded, $records->count());
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('forgetSelected')
                    ->label('Forget selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Forget selected failed jobs')
                    ->modalDescription('Each selected job is permanently removed from the failed jobs list.')
                    ->action(function (Collection $records): void {
                        /** @var Collection<int, FailedJob> $records */
                        $succeeded = 0;
                        foreach ($records as $record) {
                            if (self::forgetFailedJob($record->uuid)) {
                                $succeeded++;
                            }
                        }

                        self::notifyBulkResult('forgotten', $succeeded, $records->count());
                    })
                    ->deselectRecordsAfterCompletion(),
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

    public static function retryFailedJob(string $failedJobUuid): bool
    {
        /** @var object{connection: string, payload: string, queue: string}|null $failedJob */
        $failedJob = app('queue.failer')->find($failedJobUuid);

        if ($failedJob === null) {
            return false;
        }

        app('queue')->connection($failedJob->connection)->pushRaw(self::resetAttempts($failedJob->payload), $failedJob->queue);
        app('queue.failer')->forget($failedJobUuid);

        app(Recorder::class)->recordAdminAction('operations.retry_failed_job', ['failed_job_uuid' => $failedJobUuid]);

        return true;
    }

    /**
     * A failed job's payload carries the exhausted attempts count it failed
     * with (Laravel's queue drivers increment `attempts` in the payload
     * itself). Re-pushing it unchanged makes the worker fail it again on
     * sight, via the max-attempts guard, without ever re-running the job —
     * mirrors Laravel's own queue:retry (Illuminate\Queue\Console\RetryCommand::resetAttempts()).
     */
    private static function resetAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        $decoded['attempts'] = 0;

        return json_encode($decoded) ?: $payload;
    }

    public static function forgetFailedJob(string $failedJobUuid): bool
    {
        $forgotten = app('queue.failer')->forget($failedJobUuid);

        if ($forgotten) {
            app(Recorder::class)->recordAdminAction('operations.forget_failed_job', ['failed_job_uuid' => $failedJobUuid]);
        }

        return $forgotten;
    }

    public static function notifyRetryResult(bool $succeeded): void
    {
        $succeeded
            ? Notification::make()->title('Failed job retried')->success()->send()
            : Notification::make()->title('Failed job not found')->warning()->send();
    }

    public static function notifyForgetResult(bool $succeeded): void
    {
        $succeeded
            ? Notification::make()->title('Failed job removed')->success()->send()
            : Notification::make()->title('Failed job not found')->warning()->send();
    }

    private static function notifyBulkResult(string $verb, int $succeeded, int $total): void
    {
        $missing = $total - $succeeded;

        $title = "{$succeeded} job(s) {$verb}";
        if ($missing > 0) {
            $title .= ", {$missing} could not be resolved";
        }

        Notification::make()->title($title)->success()->send();
    }

    public static function payloadFull(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[payload unavailable]';
        }

        return $payload;
    }

    private static function repositoryLabel(string $payload): ?string
    {
        $target = JobPayloadInspector::repositoryTarget($payload);

        return $target === null ? null : "{$target['project']} / {$target['repository']}";
    }
}
