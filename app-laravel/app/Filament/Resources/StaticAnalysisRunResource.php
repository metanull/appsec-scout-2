<?php

namespace App\Filament\Resources;

use App\Audit\Recorder;
use App\Filament\Resources\StaticAnalysisRunResource\Pages\ListStaticAnalysisRuns;
use App\Filament\Resources\StaticAnalysisRunResource\Pages\ViewStaticAnalysisRun;
use App\Filament\Support\DateRangeFilters;
use App\Models\StaticAnalysisRun;
use Filament\Actions\Action;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Phiki\Grammar\Grammar;

class StaticAnalysisRunResource extends Resource
{
    protected static ?string $model = StaticAnalysisRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.queue') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Static Analysis Run')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('source_control_id')
                            ->label('Source Control')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'success' => 'success',
                                'failure' => 'danger',
                                'partial' => 'warning',
                                default => 'warning',
                            }),
                        TextEntry::make('batch_id')
                            ->label('Batch ID')
                            ->placeholder('-'),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime('d M Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('finished_at')
                            ->label('Finished')
                            ->dateTime('d M Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('error_message')
                            ->label('Error')
                            ->wrap()
                            ->placeholder('-')
                            ->columnSpan(2),
                    ]),
                ]),

            Section::make('Counts')
                ->collapsible()
                ->schema([
                    CodeEntry::make('_counts')
                        ->label('')
                        ->state(fn (StaticAnalysisRun $record): array => (array) $record->counts_json)
                        ->grammar(Grammar::Json)
                        ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        ->copyable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_control_id')
                    ->label('Source Control')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failure' => 'danger',
                        'partial' => 'warning',
                        default => 'warning',
                    }),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->since()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('counts_json')
                    ->label('Counts')
                    ->state(fn (StaticAnalysisRun $record): string => static::formatCounts($record))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_control_id')
                    ->options(fn (): array => StaticAnalysisRun::query()->distinct()->pluck('source_control_id', 'source_control_id')->all()),
                SelectFilter::make('status')
                    ->options(['success' => 'Success', 'partial' => 'Partial', 'failure' => 'Failure', 'running' => 'Running']),
                ...DateRangeFilters::for('started_at', 'Started from', 'Started until'),
            ])
            ->actions([
                Action::make('forceFinish')
                    ->label('Force-finish')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->visible(fn (StaticAnalysisRun $record): bool => $record->status === 'running')
                    ->requiresConfirmation()
                    ->modalDescription('Marks this run as finished so a new "Run static analysis" sweep can be queued. Any AnalyzeRepositoryJob instances still in flight on the static-analysis-collector are not stopped; they will keep running against this now-closed run but have no further effect on it.')
                    ->action(fn (StaticAnalysisRun $record) => static::forceFinishRun($record)),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (StaticAnalysisRun $record): string => StaticAnalysisRunResource::getUrl('view', ['record' => $record]));
    }

    public static function formatCounts(StaticAnalysisRun $run): string
    {
        $counts = $run->getAttribute('counts_json');
        $counts = is_array($counts) ? $counts : [];

        $considered = (int) ($counts['repositories_considered'] ?? 0);
        $completed = (int) ($counts['repositories_completed'] ?? 0);
        $failed = (int) ($counts['repositories_failed'] ?? 0);

        return "{$completed} / {$considered} · {$failed} failed";
    }

    /**
     * Recovers a run stuck at status "running" - e.g. a static-analysis-collector job
     * died mid-flight without its failed() hook firing - which otherwise blocks every
     * future dispatch (see OperationsPage::dispatchRunStaticAnalysis()). Whatever counts
     * had accumulated are left as-is; only status, finished_at, and error_message change.
     */
    public static function forceFinishRun(StaticAnalysisRun $record): void
    {
        $record->update([
            'status' => 'failure',
            'finished_at' => now(),
            'error_message' => 'Force-finished by an operator: the run did not complete on its own.',
        ]);

        app(Recorder::class)->recordAdminAction('operations.force_finish_static_analysis_run', [
            'static_analysis_run_id' => $record->id,
        ]);

        Notification::make()->title('Run marked as finished')->success()->send();
    }

    /**
     * URL to the error log, pre-filtered to failures logged against this run
     * (see ErrorLog::context_json.run, set by AnalyzeRepositoryJob::logFailure()
     * and DispatchStaticAnalysisRunsJob::buildTargets()).
     */
    public static function failuresUrl(StaticAnalysisRun $run): string
    {
        $params = [
            'tab' => 'static-analysis',
            'tableFilters' => [
                'run' => ['value' => (string) $run->id],
            ],
        ];

        return ErrorLogResource::getUrl('index') . '?' . http_build_query($params);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticAnalysisRuns::route('/'),
            'view' => ViewStaticAnalysisRun::route('/{record}'),
        ];
    }
}
