<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Models\ErrorLog;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ErrorLogResource extends Resource
{
    protected static ?string $model = ErrorLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Errors';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.errors') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                TextColumn::make('level')->badge()->color(fn (string $state) => match (strtolower($state)) {
                    'error', 'critical', 'alert', 'emergency' => 'danger',
                    'warning' => 'warning',
                    default => 'secondary',
                }),
                TextColumn::make('channel'),
                TextColumn::make('message')->searchable()->wrap(),
                TextColumn::make('trace')
                    ->limit(500)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->options(['ERROR' => 'Error', 'CRITICAL' => 'Critical', 'ALERT' => 'Alert', 'EMERGENCY' => 'Emergency']),
                Filter::make('date_from')
                    ->form([DatePicker::make('date_from')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date_from'],
                        fn (Builder $q, string $v) => $q->whereDate('occurred_at', '>=', Carbon::parse($v)),
                    )),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListErrorLogs::route('/'),
        ];
    }
}
