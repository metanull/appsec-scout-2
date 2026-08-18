<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\FailedJobResource;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PendingJobsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static bool $shouldRegisterNavigation = false;

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
            ->records(function (?array $filters): array {
                $jobs = app(QueueRuntimeInspector::class)->pendingJobs();

                $queue = $filters['queue']['value'] ?? null;

                if (filled($queue)) {
                    $jobs = array_values(array_filter($jobs, fn (array $job): bool => $job['queue'] === $queue));
                }

                $keyName = ArrayRecord::getKeyName();

                return array_map(
                    fn (array $job): array => [...$job, $keyName => static::jobKey($job['queue'], $job['payload'])],
                    $jobs,
                );
            })
            ->columns([
                TextColumn::make('queue')
                    ->badge(),
                TextColumn::make('job')
                    ->label('Job')
                    ->getStateUsing(fn (array $record): string => FailedJobResource::jobName($record['payload']))
                    ->wrap(),
                TextColumn::make('source_tracker')
                    ->label('Source / Tracker')
                    ->getStateUsing(fn (array $record): string => FailedJobResource::sourceOrTracker($record['payload']))
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
            ->paginated(false);
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
            'job' => FailedJobResource::jobName($payload),
        ]);

        Notification::make()->title('Job removed from queue')->success()->send();
    }
}
