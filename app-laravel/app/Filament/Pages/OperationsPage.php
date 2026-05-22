<?php

namespace App\Filament\Pages;

use App\Audit\Recorder;
use App\Integrations\DispatchDueIntegrations;
use App\Jobs\PruneAuditLogs;
use App\Jobs\PruneErrorLogs;
use App\Jobs\UpdateTrivyDbJob;
use App\Models\ErrorLog;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Registry as SourceRegistry;
use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use App\Trackers\Registry as TrackerRegistry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class OperationsPage extends Page
{
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

    public function queuedJobCount(): int
    {
        return max(Queue::size('default'), (int) DB::table('jobs')->count());
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
                'exception_preview' => Str::limit($this->redactString((string) $row->exception), 1000),
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

    public function dispatchSelectedSource(): void
    {
        if (! is_string($this->selectedSourceId) || $this->selectedSourceId === '') {
            Notification::make()->title('Select a source first')->warning()->send();

            return;
        }

        FetchSourceJob::dispatch($this->selectedSourceId);
        app(Recorder::class)->recordAdminAction('operations.dispatch_source_fetch', ['source_id' => $this->selectedSourceId]);

        Notification::make()->title('Source fetch queued')->success()->send();
    }

    public function dispatchSelectedTracker(): void
    {
        if (! is_string($this->selectedTrackerId) || $this->selectedTrackerId === '') {
            Notification::make()->title('Select a tracker first')->warning()->send();

            return;
        }

        RefreshWorkItemsJob::dispatch($this->selectedTrackerId);
        app(Recorder::class)->recordAdminAction('operations.dispatch_tracker_refresh', ['tracker_id' => $this->selectedTrackerId]);

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

    private function payloadPreview(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $encoded = json_encode($this->redactArray($decoded), JSON_UNESCAPED_SLASHES);

            return Str::limit($encoded ?: '[payload unavailable]', 240);
        }

        return Str::limit($this->redactString($payload), 240);
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
