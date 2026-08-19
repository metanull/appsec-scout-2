<?php

namespace App\Filament\Resources\ErrorLogResource\Pages;

use App\Audit\Recorder;
use App\Filament\Resources\ErrorLogResource;
use App\Jobs\PruneErrorLogs;
use App\Models\ErrorLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListErrorLogs extends ListRecords
{
    protected static string $resource = ErrorLogResource::class;

    private const CHANNEL_LABELS = [
        'repository-collection' => 'Repository Collection',
        'static-analysis' => 'Static Analysis',
        'static-analysis-import' => 'Static Analysis Import',
        'sbom-import' => 'SBOM Import',
        'sync' => 'Sync',
        'database' => 'Application',
    ];

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cleanUpNow')
                ->label('Clean up now')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => ErrorLogResource::canViewAny())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => sprintf(
                    'Permanently deletes every error log older than the configured retention window (%d day(s)). This cannot be undone.',
                    self::retainDays(),
                ))
                ->action(function (): void {
                    $retainDays = self::retainDays();
                    $deleted = (new PruneErrorLogs($retainDays))->handle();

                    app(Recorder::class)->recordAdminAction('operations.prune_error_logs', [
                        'deleted' => $deleted,
                        'retain_days' => $retainDays,
                    ]);

                    Notification::make()
                        ->title("{$deleted} error log(s) deleted")
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function retainDays(): int
    {
        return (int) config('logging.error_retain_days', 90);
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')];

        foreach (self::CHANNEL_LABELS as $channel => $label) {
            // Larastan's where() stub types $column as model-property<TModel> only,
            // and this untemplated Builder param can't be resolved to ErrorLog.
            // @phpstan-ignore argument.type
            $modifyQuery = fn (Builder $query): Builder => $query->where('channel', $channel);

            $tabs[$channel] = Tab::make($label)
                ->modifyQueryUsing($modifyQuery)
                ->badge(fn (): int => ErrorLog::query()->where('channel', $channel)->count());
        }

        return $tabs;
    }
}
