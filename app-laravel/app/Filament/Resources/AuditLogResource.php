<?php

namespace App\Filament\Resources;

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Audit Log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.audit') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('actor_kind')->badge(),
                TextColumn::make('action')->searchable(),
                TextColumn::make('subject_type')->label('Subject type'),
                TextColumn::make('subject_id')->label('Subject ID'),
                TextColumn::make('payload_json')
                    ->label('Payload')
                    ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_id')
                    ->label('User')
                    ->formatStateUsing(fn (mixed $state): string => $state !== null ? "User #{$state}" : '—'),
                TextColumn::make('ip')->label('IP'),
            ])
            ->filters([
                SelectFilter::make('actor_kind')
                    ->options(['user' => 'User', 'job' => 'Job', 'cli' => 'CLI', 'system' => 'System']),
                Filter::make('date_from')
                    ->form([DatePicker::make('date_from')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date_from'],
                        fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', Carbon::parse($v)),
                    )),
                Filter::make('date_until')
                    ->form([DatePicker::make('date_until')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date_until'],
                        fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', Carbon::parse($v)),
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (AuditLog $record): string => AuditLogResource::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
