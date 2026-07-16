<?php

namespace App\Filament\Widgets;

use App\Models\ErrorLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RecentErrorsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Recent errors';

    protected static bool $isLazy = false;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can('admin.queue') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ErrorLog::query()->latest('occurred_at')->limit(5))
            ->columns([
                TextColumn::make('channel')
                    ->label('Channel')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->color(fn (ErrorLog $record): string => match ($record->level) {
                        'emergency', 'alert', 'critical', 'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('-'),
                TextColumn::make('message')
                    ->label('Message')
                    ->wrap()
                    ->grow()
                    ->placeholder('-'),
                TextColumn::make('occurred_at')
                    ->label('Occurred')
                    ->since()
                    ->placeholder('-'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No recent errors recorded');
    }
}
