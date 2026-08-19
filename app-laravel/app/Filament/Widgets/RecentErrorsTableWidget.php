<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ErrorLogResource;
use App\Filament\Support\LogLevelBadgeColor;
use App\Models\ErrorLog;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RecentErrorsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Recent errors';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can('admin.queue') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ErrorLog::query()->where('occurred_at', '>=', now()->subDay())->latest('occurred_at')->limit(5))
            ->columns([
                TextColumn::make('channel')
                    ->label('Channel')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('level')
                    ->label('Level')
                    ->badge()
                    ->color(fn (ErrorLog $record): string => LogLevelBadgeColor::for($record->level))
                    ->placeholder('-'),
                TextColumn::make('message')
                    ->label('Message')
                    ->wrap()
                    ->grow()
                    ->placeholder('-'),
                TextColumn::make('context_json')
                    ->label('Context')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? (json_encode($state, JSON_UNESCAPED_SLASHES) ?: '') : '')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-'),
                TextColumn::make('occurred_at')
                    ->label('Occurred')
                    ->since()
                    ->placeholder('-'),
            ])
            ->headerActions([
                Action::make('viewAll')
                    ->label('View all')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (): string => ErrorLogResource::getUrl('index')),
            ])
            ->recordUrl(fn (ErrorLog $record): string => ErrorLogResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('No errors in the last 24 hours');
    }
}
