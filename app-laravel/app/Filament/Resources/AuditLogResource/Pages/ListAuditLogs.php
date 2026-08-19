<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\AuditLogResource;
use App\Jobs\PruneAuditLogs;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cleanUpNow')
                ->label('Clean up now')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => AuditLogResource::canViewAny())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => sprintf(
                    'Permanently deletes every audit log older than the configured retention window (%d day(s)). This cannot be undone.',
                    self::retainDays(),
                ))
                ->action(function (): void {
                    $retainDays = self::retainDays();
                    $deleted = (new PruneAuditLogs($retainDays))->handle();

                    app(Recorder::class)->recordAdminAction('operations.prune_audit_logs', [
                        'deleted' => $deleted,
                        'retain_days' => $retainDays,
                    ]);

                    Notification::make()
                        ->title("{$deleted} audit log(s) deleted")
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function retainDays(): int
    {
        return (int) config('audit.retain_days', 365);
    }
}
