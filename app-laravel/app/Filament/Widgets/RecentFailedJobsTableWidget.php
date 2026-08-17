<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FailedJobResource;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RecentFailedJobsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Recent failed jobs';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can('admin.queue') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FailedJob::query()->latest('failed_at')->limit(5))
            ->columns([
                TextColumn::make('failed_at')
                    ->label('Failed at')
                    ->dateTime(),
                TextColumn::make('queue')
                    ->badge(),
                TextColumn::make('job')
                    ->label('Job')
                    ->getStateUsing(fn (FailedJob $record): string => FailedJobResource::jobName($record->payload))
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'Unknown job')
                    ->wrap(),
                TextColumn::make('exception_summary')
                    ->label('Exception')
                    ->getStateUsing(fn (FailedJob $record): string => FailedJobResource::exceptionPreview($record->exception))
                    ->wrap()
                    ->limit(200),
            ])
            ->headerActions([
                Action::make('viewAll')
                    ->label('View all')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (): string => FailedJobResource::getUrl('index')),
            ])
            ->recordUrl(fn (FailedJob $record): string => FailedJobResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateDescription('No failed jobs recorded.');
    }
}
