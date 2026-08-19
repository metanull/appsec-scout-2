<?php

namespace App\Filament\Resources\FailedJobResource\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\FailedJobResource;
use App\Jobs\PruneFailedJobs;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFailedJobs extends ListRecords
{
    protected static string $resource = FailedJobResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cleanUpNow')
                ->label('Clean up now')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => FailedJobResource::canViewAny())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => sprintf(
                    'Permanently deletes every failed job older than the configured retention window (%d day(s)). This cannot be undone; retry any job you still need before cleaning up.',
                    self::retainDays(),
                ))
                ->action(function (): void {
                    $retainDays = self::retainDays();
                    $deleted = (new PruneFailedJobs($retainDays))->handle();

                    app(Recorder::class)->recordAdminAction('operations.prune_failed_jobs', [
                        'deleted' => $deleted,
                        'retain_days' => $retainDays,
                    ]);

                    Notification::make()
                        ->title("{$deleted} failed job(s) deleted")
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function retainDays(): int
    {
        return (int) config('queue.failed.retain_days', 90);
    }
}
