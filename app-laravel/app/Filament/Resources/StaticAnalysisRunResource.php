<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaticAnalysisRunResource\Pages\ListStaticAnalysisRuns;
use App\Filament\Resources\StaticAnalysisRunResource\Pages\ViewStaticAnalysisRun;
use App\Filament\Resources\Support\CollectionRunResource;
use App\Models\StaticAnalysisRun;

class StaticAnalysisRunResource extends CollectionRunResource
{
    protected static ?string $model = StaticAnalysisRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Analysis Runs';

    protected static function runLabel(): string
    {
        return 'Static Analysis Run';
    }

    protected static function forceFinishModalDescription(): string
    {
        return 'Marks this run as finished so a new "Run static analysis" sweep can be queued. Any AnalyzeRepositoryJob instances still in flight on the static-analysis-collector are not stopped; they will keep running against this now-closed run but have no further effect on it.';
    }

    protected static function forceFinishAuditAction(): string
    {
        return 'operations.force_finish_static_analysis_run';
    }

    protected static function forceFinishAuditPayloadKey(): string
    {
        return 'static_analysis_run_id';
    }

    protected static function errorLogChannelTab(): string
    {
        return 'static-analysis';
    }

    protected static function runModelClass(): string
    {
        return StaticAnalysisRun::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticAnalysisRuns::route('/'),
            'view' => ViewStaticAnalysisRun::route('/{record}'),
        ];
    }
}
