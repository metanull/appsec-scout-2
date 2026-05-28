<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Integrations\DispatchDueIntegrations;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Jobs\UpdateTrivyDbJob;
use App\Models\ErrorLog;
use App\Models\FailedJob;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Operations';

    protected static ?string $slug = 'admin/operations';

    protected string $view = 'filament.pages.operations-page';

    public ?string $selectedSourceId = null;

    public ?string $selectedTrackerId = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.queue') : false;
    }

    public function mount(): void
    {
        $this->selectedSourceId = $this->sourceOptions() !== [] ? array_key_first($this->sourceOptions()) : null;
        $this->selectedTrackerId = $this->trackerOptions() !== [] ? array_key_first($this->trackerOptions()) : null;
    }

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('dispatchDueIntegrations')
                ->label('Dispatch due integrations')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->action(fn () => $this->dispatchDueIntegrationsNow()),

            Action::make('fetchSource')
                ->label('Fetch source')
                ->icon('heroicon-o-arrow-down-tray')
                ->form([
                    Select::make('source_id')
                        ->label('Source')
                        ->options(fn (): array => $this->sourceOptions())
                        ->required(),
                ])
                ->action(fn (array $data) => $this->dispatchSelectedSource($data['source_id'])),

            Action::make('refreshTracker')
                ->label('Refresh tracker')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    Select::make('tracker_id')
                        ->label('Tracker')
                        ->options(fn (): array => $this->trackerOptions())
                        ->required(),
                ])
                ->action(fn (array $data) => $this->dispatchSelectedTracker($data['tracker_id'])),

            ActionGroup::make([
                Action::make('pruneAuditLogs')
                    ->label('Prune audit logs')
                    ->requiresConfirmation()
                    ->action(fn () => $this->pruneAuditLogsNow()),

                Action::make('pruneErrorLogs')
                    ->label('Prune error logs')
                    ->requiresConfirmation()
                    ->action(fn () => $this->pruneErrorLogsNow()),

                Action::make('updateTrivyDb')
                    ->label('Update Trivy DB')
                    ->requiresConfirmation()
                    ->action(fn () => $this->updateTrivyDbNow()),
            ])->label('Maintenance'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FailedJob::query()->latest('failed_at'))
            ->columns([
                TextColumn::make('failed_at')
                    ->label('Failed at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('queue')
                    ->badge(),
                TextColumn::make('job')
                    ->label('Job')
                    ->getStateUsing(fn (FailedJob $record): string => $this->jobName($record->payload))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('payload LIKE ?', ["%{$search}%"])),
                TextColumn::make('exception_summary')
                    ->label('Exception')
                    ->getStateUsing(fn (FailedJob $record): string => $this->exceptionPreview($record->exception))
                    ->wrap()
                    ->limit(200),
                TextColumn::make('source_tracker')
                    ->label('Source / Tracker')
                    ->getStateUsing(fn (FailedJob $record): string => $this->sourceOrTracker($record->payload)),
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
                    ->action(fn (FailedJob $record) => $this->retryFailedJob($record->uuid)),
                Action::make('forget')
                    ->label('Forget')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => $this->forgetFailedJob($record->uuid)),
                Action::make('details')
                    ->label('Details')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->fillForm(fn (FailedJob $record): array => [
                        'exception' => $this->redactString($record->exception),
                        'payload' => $this->payloadFull($record->payload),
                    ])
                    ->form([
                        Textarea::make('exception')
                            ->label('Exception')
                            ->rows(10)
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('payload')
                            ->label('Payload')
                            ->rows(8)
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateDescription('No failed jobs recorded.')
            ->heading('Failed jobs');
    }

    public function queuedJobCount(): int
    {
        return (int) DB::table('jobs')->count();
    }

    public function runningSyncCount(): int
    {
        return (int) SyncRun::query()->where('status', 'running')->count();
    }

    public function failedJobCount(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    /** @return list<array{id: int, uuid: string, queue: string, failed_at: string, job: string, exception_preview: string, payload_preview: string}> */
    public function recentFailedJobs(): array
    {
        /** @var list<array{id: int, uuid: string, queue: string, failed_at: string, job: string, exception_preview: string, payload_preview: string}> $failedJobs */
        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(10)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'queue' => (string) $row->queue,
                'failed_at' => (string) $row->failed_at,
                'job' => $this->jobName((string) $row->payload),
                'exception_preview' => $this->exceptionPreview((string) $row->exception),
                'payload_preview' => $this->payloadPreview((string) $row->payload),
            ])
            ->values()
            ->all();

        return $failedJobs;
    }

    /** @return list<SyncRun> */
    public function recentSyncRuns(): array
    {
        /** @var list<SyncRun> $runs */
        $runs = SyncRun::query()->latest('id')->limit(10)->get()->values()->all();

        return $runs;
    }

    /** @return list<ErrorLog> */
    public function recentErrors(): array
    {
        /** @var list<ErrorLog> $errors */
        $errors = ErrorLog::query()->latest('occurred_at')->limit(10)->get()->values()->all();

        return $errors;
    }

    /** @return list<array{id: string, cadence: string}> */
    public function scheduleEntries(): array
    {
        return [
            ['id' => 'integrations:dispatch-due', 'cadence' => 'Every minute'],
            ['id' => 'prune-audit-logs', 'cadence' => 'Daily'],
            ['id' => 'prune-error-logs', 'cadence' => 'Daily'],
            ['id' => 'update-trivy-db', 'cadence' => 'Daily'],
        ];
    }

    /** @return array<string, string> */
    public function sourceOptions(): array
    {
        $options = [];

        foreach (app(SourceRegistry::class)->all() as $source) {
            $options[$source->id()] = $source->displayName();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function trackerOptions(): array
    {
        $options = [];

        foreach (app(TrackerRegistry::class)->all() as $tracker) {
            $options[$tracker->id()] = $tracker->displayName();
        }

        return $options;
    }

    public function dispatchDueIntegrationsNow(): void
    {
        $count = app(DispatchDueIntegrations::class)->dispatchDue();

        app(Recorder::class)->recordAdminAction('operations.dispatch_due_integrations', ['count' => $count]);

        Notification::make()->title("Queued {$count} due integration job(s)")->success()->send();
    }

    public function dispatchSelectedSource(string $override = ''): void
    {
        $id = $override !== '' ? $override : ($this->selectedSourceId ?? '');

        if ($id === '') {
            Notification::make()->title('Select a source first')->warning()->send();

            return;
        }

        FetchSourceJob::dispatch($id);
        app(Recorder::class)->recordAdminAction('operations.dispatch_source_fetch', ['source_id' => $id]);

        Notification::make()->title('Source fetch queued')->success()->send();
    }

    public function dispatchSelectedTracker(string $override = ''): void
    {
        $id = $override !== '' ? $override : ($this->selectedTrackerId ?? '');

        if ($id === '') {
            Notification::make()->title('Select a tracker first')->warning()->send();

            return;
        }

        RefreshWorkItemsJob::dispatch($id);
        app(Recorder::class)->recordAdminAction('operations.dispatch_tracker_refresh', ['tracker_id' => $id]);

        Notification::make()->title('Tracker refresh queued')->success()->send();
    }

    public function pruneAuditLogsNow(): void
    {
        (new PruneAuditLogs((int) config('audit.retain_days', 365)))->handle();
        app(Recorder::class)->recordAdminAction('operations.prune_audit_logs');

        Notification::make()->title('Audit logs pruned')->success()->send();
    }

    public function pruneErrorLogsNow(): void
    {
        (new PruneErrorLogs((int) config('logging.error_retain_days', 90)))->handle();
        app(Recorder::class)->recordAdminAction('operations.prune_error_logs');

        Notification::make()->title('Error logs pruned')->success()->send();
    }

    public function updateTrivyDbNow(): void
    {
        UpdateTrivyDbJob::dispatch();
        app(Recorder::class)->recordAdminAction('operations.update_trivy_db');

        Notification::make()->title('Trivy DB update queued')->success()->send();
    }

    public function retryFailedJob(string $failedJobUuid): void
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

    public function forgetFailedJob(string $failedJobUuid): void
    {
        app('queue.failer')->forget($failedJobUuid);

        app(Recorder::class)->recordAdminAction('operations.forget_failed_job', ['failed_job_uuid' => $failedJobUuid]);

        Notification::make()->title('Failed job removed')->success()->send();
    }

    private function payloadFull(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            return json_encode($this->redactArray($decoded), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[payload unavailable]';
        }

        return $this->redactString($payload);
    }

    private function payloadPreview(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $encoded = json_encode($this->redactArray($decoded), JSON_UNESCAPED_SLASHES);

            return Str::limit($encoded ?: '[payload unavailable]', 240);
        }

        return Str::limit($this->redactString($payload), 240);
    }

    private function exceptionPreview(string $exception): string
    {
        if (str_contains($exception, 'Data too long for column')) {
            $column = Str::between($exception, "Data too long for column '", "'");

            return $column !== ''
                ? "Database value exceeded security_events.{$column}. Run migrations, then retry or forget this failed job."
                : 'Database value exceeded a column size. Run migrations, then retry or forget this failed job.';
        }

        return Str::limit($this->redactString($exception), 1000);
    }

    private function jobName(string $payload): string
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

    private function sourceOrTracker(string $payload): string
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

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);

                continue;
            }

            if (is_scalar($value) && $this->isSensitiveKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_string($value) ? $this->redactString($value) : $value;
        }

        return $redacted;
    }

    private function redactString(string $value): string
    {
        return (string) preg_replace(
            '/((token|secret|password|api[_-]?key|pat|authorization)[^=:\"]*[=:\"]\s*)([^\s\",}]+)/i',
            '$1[redacted]',
            $value,
        );
    }

    private function isSensitiveKey(string $key): bool
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
