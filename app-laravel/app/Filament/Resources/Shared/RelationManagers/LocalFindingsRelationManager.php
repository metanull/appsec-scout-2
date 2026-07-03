<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Filament\Resources\SecurityEventResource;
use App\Models\LocalFinding;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class LocalFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'localFindings';

    protected static ?string $title = 'Local findings';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge()->color(fn (string $state): string => $state === LocalFinding::KIND_SECRET ? 'danger' : 'warning'),
                TextColumn::make('severity')->badge()->color(fn (?string $state): string => self::severityColor($state))->placeholder('-'),
                TextColumn::make('title')->searchable()->wrap()->grow(),
                TextColumn::make('file_path')->label('Location')
                    ->formatStateUsing(fn (LocalFinding $record): string => $record->start_line !== null
                        ? "{$record->file_path}:{$record->start_line}"
                        : $record->file_path)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('package_name')->label('Package')
                    ->formatStateUsing(fn (?string $state, LocalFinding $record): ?string => $state !== null
                        ? trim("{$state} {$record->package_version}")
                        : null)
                    ->placeholder('-'),
                TextColumn::make('correlated_security_event_id')
                    ->label('Correlated alert')
                    ->state(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? '#' . $record->correlated_security_event_id : '-')
                    ->url(fn (LocalFinding $record): ?string => $record->correlated_security_event_id !== null
                        ? SecurityEventResource::getUrl('view', ['record' => $record->correlated_security_event_id])
                        : null)
                    ->color(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? 'primary' : 'gray'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
            ])
            ->defaultSort('severity')
            ->emptyStateDescription('No local findings recorded yet.')
            ->paginated([25, 50, 100]);
    }

    private static function severityColor(?string $severity): string
    {
        return match (strtoupper((string) $severity)) {
            'CRITICAL' => 'danger',
            'HIGH' => 'warning',
            'MEDIUM' => 'info',
            'LOW', 'UNKNOWN' => 'gray',
            default => 'gray',
        };
    }
}
