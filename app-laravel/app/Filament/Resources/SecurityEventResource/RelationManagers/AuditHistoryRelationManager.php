<?php

namespace App\Filament\Resources\SecurityEventResource\RelationManagers;

use App\Audit\AuditLog;
use Filament\Actions\Action;
use Filament\Infolists\Components\CodeEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Phiki\Grammar\Grammar;

class AuditHistoryRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Audit History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('action')
                    ->label('Action')
                    ->searchable()
                    ->grow(),
                TextColumn::make('actor_kind')
                    ->label('Actor')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'user' => 'info',
                        'job' => 'gray',
                        'cli' => 'warning',
                        default => 'secondary',
                    }),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->actions([
                Action::make('viewPayload')
                    ->label('Payload')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Audit record payload')
                    ->infolist([
                        CodeEntry::make('payload_json')
                            ->label('')
                            ->state(fn (AuditLog $record): array => is_array($record->payload_json) ? $record->payload_json : [])
                            ->grammar(Grammar::Json)
                            ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            ->columnSpanFull(),
                    ])
                    ->modalSubmitAction(false)
                    ->visible(fn (AuditLog $record): bool => is_array($record->payload_json) && $record->payload_json !== []),
            ]);
    }
}
