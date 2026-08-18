<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Filament\Support\JobPayloadInspector;
use App\Queue\QueueRuntimeInspector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Backed by an array data source (QueueRuntimeInspector::pendingJobs()), not
 * an Eloquent query, since the queue driver (database table or Redis list)
 * isn't a model — so unlike a resource ListRecords page, this table's
 * filter/sort state is not persisted in the URL: plain Page +
 * InteractsWithTable only gets Filament's URL bindings via a query()/
 * relationship(), neither of which applies here.
 */
class PendingJobsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Queues';

    protected static ?string $slug = 'admin/queues';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('admin.queue') ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator {
                $jobs = app(QueueRuntimeInspector::class)->pendingJobs();

                $queue = $filters['queue']['value'] ?? null;

                if (filled($queue)) {
                    $jobs = array_values(array_filter($jobs, fn (array $job): bool => $job['queue'] === $queue));
                }

                $keyName = ArrayRecord::getKeyName();

                $records = array_map(
                    fn (array $job): array => [...$job, $keyName => static::jobKey($job['queue'], $job['payload'])],
                    $jobs,
                );

                $page = (int) $page;
                $perPage = (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    array_slice($records, ($page - 1) * $perPage, $perPage),
                    count($records),
                    $perPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('queue')
                    ->badge(),
                TextColumn::make('job')
                    ->label('Job')
                    ->getStateUsing(fn (array $record): string => JobPayloadInspector::jobName($record['payload']))
                    ->wrap(),
                TextColumn::make('source_tracker')
                    ->label('Source / Tracker')
                    ->getStateUsing(fn (array $record): string => JobPayloadInspector::sourceOrTracker($record['payload']))
                    ->placeholder('-'),
                TextColumn::make('repository')
                    ->label('Repository')
                    ->getStateUsing(fn (array $record): ?string => self::repositoryLabel($record['payload']))
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->options(fn (): array => array_combine(
                        $names = app(QueueRuntimeInspector::class)->allQueueNames(),
                        $names,
                    )),
            ])
            ->actions([
                Action::make('drop')
                    ->label('Drop')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This removes the job from the queue. It will not run.')
                    ->visible(fn (): bool => Auth::user()?->can('admin.queue') ?? false)
                    ->action(fn (array $record) => self::dropJob($record['queue'], $record['payload'])),
            ])
            ->emptyStateHeading('No jobs pending')
            ->paginated([25, 50, 100])
            ->poll('30s');
    }

    public static function jobKey(string $queue, string $payload): string
    {
        return md5($queue . '|' . $payload);
    }

    public static function dropJob(string $queue, string $payload): void
    {
        Gate::authorize('admin.queue');

        app(QueueRuntimeInspector::class)->dropPendingJob($queue, $payload);

        app(Recorder::class)->recordAdminAction('operations.drop_pending_job', [
            'queue' => $queue,
            'job' => JobPayloadInspector::jobName($payload),
        ]);

        Notification::make()->title('Job removed from queue')->success()->send();
    }

    private static function repositoryLabel(string $payload): ?string
    {
        $target = JobPayloadInspector::repositoryTarget($payload);

        return $target === null ? null : "{$target['project']} / {$target['repository']}";
    }
}
