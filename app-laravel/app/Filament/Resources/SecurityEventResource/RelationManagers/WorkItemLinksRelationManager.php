<?php

namespace App\Filament\Resources\SecurityEventResource\RelationManagers;

use App\Filament\Resources\SecurityEventResource;
use App\Models\WorkItemLink;
use App\Trackers\WorkItemService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class WorkItemLinksRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'workItemLinks';

    protected static ?string $title = 'Work Items';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tracker_id')
                    ->label('Tracker')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('work_item_title')
                    ->label('Work item')
                    ->description(fn (WorkItemLink $record): string => $record->work_item_id)
                    ->url(fn (WorkItemLink $record): ?string => $record->work_item_url ?: null)
                    ->openUrlInNewTab()
                    ->placeholder('-')
                    ->wrap()
                    ->grow(),
                TextColumn::make('work_item_state')
                    ->label('State')
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),
                TextColumn::make('_linked_alerts')
                    ->label('Linked alerts')
                    ->state(fn (WorkItemLink $record): int => WorkItemLink::query()
                        ->where('tracker_id', $record->tracker_id)
                        ->where('work_item_id', $record->work_item_id)
                        ->count())
                    ->formatStateUsing(fn (int $state): string => $state === 1 ? '1 alert' : $state . ' alerts')
                    ->url(fn (WorkItemLink $record): string => SecurityEventResource::workItemFilterUrl(
                        $record->tracker_id,
                        $record->work_item_id,
                    )),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Created at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->actions([
                Action::make('unlink')
                    ->label('Unlink')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->visible(fn (): bool => Gate::allows('work-items.link'))
                    ->requiresConfirmation()
                    ->modalHeading('Unlink work item')
                    ->modalDescription('Remove the link between this alert and the work item?')
                    ->action(function (WorkItemLink $record): void {
                        Gate::authorize('work-items.link');

                        app(WorkItemService::class)->unlink($record);

                        Notification::make()->title('Work item unlinked')->success()->send();
                    }),
            ]);
    }
}
