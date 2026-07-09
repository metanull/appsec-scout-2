<?php

namespace App\Filament\Widgets;

use App\Assets\Sbom\SbomScanStatusReporter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

class SbomScanStatusWidget extends TableWidget
{
    protected static ?string $heading = 'SBOM scan status';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can('admin.queue') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => app(SbomScanStatusReporter::class)->statusForAllRuns())
            ->columns([
                TextColumn::make('run')
                    ->label('Run'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (array $record): string => match (true) {
                        $record['dryRun'] => 'Dry run',
                        $record['finished'] => 'Finished',
                        default => 'In progress',
                    })
                    ->color(fn (array $record): string => match (true) {
                        $record['dryRun'] => 'gray',
                        $record['finished'] => 'success',
                        default => 'info',
                    }),
                TextColumn::make('imported')
                    ->label('Imported')
                    ->getStateUsing(function (array $record): string {
                        if ($record['dryRun']) {
                            return '-';
                        }

                        $totalLabel = match (true) {
                            $record['total'] === null => 'unknown total',
                            $record['totalIsApprox'] => "approx {$record['total']}",
                            default => (string) $record['total'],
                        };

                        return "{$record['imported']} of {$totalLabel}";
                    }),
                TextColumn::make('failed')
                    ->label('Failed')
                    ->getStateUsing(fn (array $record): string => $record['dryRun'] ? '-' : (string) $record['failed'])
                    ->color(fn (array $record): string => ($record['failed'] ?? 0) > 0 ? 'danger' : 'gray'),
                TextColumn::make('lastUpdated')
                    ->label('Last updated')
                    ->getStateUsing(fn (array $record): string => $record['dryRun'] ? '-' : ($record['lastUpdated']?->diffForHumans() ?? 'never')),
            ])
            ->paginated(false)
            ->emptyStateHeading('No sbom-scan runs found');
    }
}
