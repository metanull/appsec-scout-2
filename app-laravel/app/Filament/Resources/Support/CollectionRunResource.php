<?php

namespace App\Filament\Resources\Support;

use App\Audit\Recorder;
use App\Filament\Resources\ErrorLogResource;
use App\Filament\Support\DateRangeFilters;
use App\Filament\Support\RunCounts;
use App\Filament\Support\RunStatusBadgeColor;
use App\Models\RepositoryCollectionRun;
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

/**
 * Shared table/infolist/action/URL logic for the two per-repository
 * collection-sweep resources (RepositoryCollectionRunResource,
 * StaticAnalysisRunResource) — near-identical apart from labels, the
 * force-finish audit action name, and the Error Log channel tab their
 * "View failures" link targets.
 *
 * SyncRunResource is deliberately NOT part of this hierarchy: it has no
 * batch_id/forceFinish/failuresUrl, its status filter has no "partial"
 * option, and its counts_json is a different shape entirely — it is not a
 * duplicate of this class and stays a plain Resource.
 */
abstract class CollectionRunResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    /** The infolist section heading, e.g. "Repository Collection Run". */
    abstract protected static function runLabel(): string;

    /** Confirmation copy for the Force-finish action's modal. */
    abstract protected static function forceFinishModalDescription(): string;

    /** AuditLog action name recorded by Force-finish. */
    abstract protected static function forceFinishAuditAction(): string;

    /** AuditLog payload key under which the run id is recorded by Force-finish. */
    abstract protected static function forceFinishAuditPayloadKey(): string;

    /**
     * The concrete run model this resource lists — same value as $model,
     * exposed as a typed hook so the source_control_id filter's query can be
     * checked against the model's real schema rather than the generic
     * Eloquent Model base getEloquentQuery() returns.
     *
     * @return class-string<RepositoryCollectionRun>|class-string<StaticAnalysisRun>
     */
    abstract protected static function runModelClass(): string;

    /** The Error Log channel tab failuresUrl() should deep-link to. */
    abstract protected static function errorLogChannelTab(): string;

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
            Section::make(static::runLabel())
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('source_control_id')
                            ->label('Source Control')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => RunStatusBadgeColor::for($state)),
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
                        ->state(fn (RepositoryCollectionRun|StaticAnalysisRun $record): array => (array) $record->counts_json)
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
                    ->color(fn (string $state): string => RunStatusBadgeColor::for($state)),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->since()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('batch_id')
                    ->label('Batch ID')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('counts_json')
                    ->label('Counts')
                    ->state(fn (RepositoryCollectionRun|StaticAnalysisRun $record): string => RunCounts::format($record->counts_json))
                    ->toggleable(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_control_id')
                    ->options(fn (): array => (static::runModelClass())::query()->distinct()->pluck('source_control_id', 'source_control_id')->all()),
                SelectFilter::make('status')
                    ->options(['success' => 'Success', 'partial' => 'Partial', 'failure' => 'Failure', 'running' => 'Running']),
                ...DateRangeFilters::for('started_at', 'Started from', 'Started until'),
            ])
            ->actions([
                Action::make('viewFailures')
                    ->label('View failures')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (RepositoryCollectionRun|StaticAnalysisRun $record): bool => static::failedCount($record) > 0)
                    ->url(fn (RepositoryCollectionRun|StaticAnalysisRun $record): string => static::failuresUrl($record)),
                Action::make('forceFinish')
                    ->label('Force-finish')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->visible(fn (RepositoryCollectionRun|StaticAnalysisRun $record): bool => $record->status === 'running')
                    ->requiresConfirmation()
                    ->modalDescription(static::forceFinishModalDescription())
                    ->action(fn (RepositoryCollectionRun|StaticAnalysisRun $record) => static::forceFinishRun($record)),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50, 100])
            ->recordUrl(fn (RepositoryCollectionRun|StaticAnalysisRun $record): string => static::getUrl('view', ['record' => $record]));
    }

    /**
     * Recovers a run stuck at status "running" - e.g. a collector job died
     * mid-flight without its failed() hook firing - which otherwise blocks
     * every future dispatch. Whatever counts had accumulated are left
     * as-is; only status, finished_at, and error_message change.
     */
    public static function forceFinishRun(RepositoryCollectionRun|StaticAnalysisRun $record): void
    {
        $record->update([
            'status' => 'failure',
            'finished_at' => now(),
            'error_message' => 'Force-finished by an operator: the run did not complete on its own.',
        ]);

        app(Recorder::class)->recordAdminAction(static::forceFinishAuditAction(), [
            static::forceFinishAuditPayloadKey() => $record->id,
        ]);

        Notification::make()->title('Run marked as finished')->success()->send();
    }

    /** Number of repositories the run failed to process, per counts_json. */
    public static function failedCount(RepositoryCollectionRun|StaticAnalysisRun $record): int
    {
        $counts = $record->getAttribute('counts_json');

        return is_array($counts) ? (int) ($counts['repositories_failed'] ?? 0) : 0;
    }

    /**
     * URL to the error log, pre-filtered to failures logged against this run
     * (see ErrorLog::context_json.run, set by the corresponding collector
     * job's logFailure() and dispatch job's buildTargets()).
     */
    public static function failuresUrl(RepositoryCollectionRun|StaticAnalysisRun $run): string
    {
        $params = [
            'tab' => static::errorLogChannelTab(),
            'filters' => [
                'run' => ['value' => (string) $run->id],
            ],
        ];

        return ErrorLogResource::getUrl('index') . '?' . http_build_query($params);
    }
}
