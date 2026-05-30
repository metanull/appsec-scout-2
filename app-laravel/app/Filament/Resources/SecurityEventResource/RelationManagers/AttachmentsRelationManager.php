<?php

namespace App\Filament\Resources\SecurityEventResource\RelationManagers;

use App\Models\EventAttachment;
use App\Triage\AttachmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class AttachmentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Attachments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->grow(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('mime')
                    ->label('Type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => self::formatBytes($state)),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->state(fn (EventAttachment $record): string => ($record->createdBy !== null ? $record->createdBy->name : null) ?? $record->created_by_command ?? '—')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->actions([
                ActionGroup::make([
                    Action::make('download')
                        ->label('Download')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (EventAttachment $record): string => route('alerts.attachments.download', [
                            'event' => $this->getOwnerRecord()->getKey(),
                            'attachment' => $record->id,
                        ]))
                        ->openUrlInNewTab(),
                    Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => Gate::allows('work-items.create'))
                        ->requiresConfirmation()
                        ->modalHeading('Delete attachment')
                        ->modalDescription('Permanently delete this attachment?')
                        ->action(function (EventAttachment $record): void {
                            Gate::authorize('work-items.create');

                            app(AttachmentService::class)->delete($record);

                            Notification::make()->title('Attachment deleted')->success()->send();
                        }),
                ]),
            ]);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }
}
