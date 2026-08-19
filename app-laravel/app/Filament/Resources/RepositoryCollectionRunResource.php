<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ListRepositoryCollectionRuns;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ViewRepositoryCollectionRun;
use App\Filament\Resources\Support\CollectionRunResource;
use App\Models\RepositoryCollectionRun;

class RepositoryCollectionRunResource extends CollectionRunResource
{
    protected static ?string $model = RepositoryCollectionRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket-square';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Collection Runs';

    protected static function runLabel(): string
    {
        return 'Repository Collection Run';
    }

    protected static function forceFinishModalDescription(): string
    {
        return 'Marks this run as finished so a new "Collect repositories" sweep can be queued. Any CollectRepositoryJob instances still in flight on the collector are not stopped; they will keep running against this now-closed run but have no further effect on it.';
    }

    protected static function forceFinishAuditAction(): string
    {
        return 'operations.force_finish_repository_collection_run';
    }

    protected static function forceFinishAuditPayloadKey(): string
    {
        return 'repository_collection_run_id';
    }

    protected static function errorLogChannelTab(): string
    {
        return 'repository-collection';
    }

    protected static function runModelClass(): string
    {
        return RepositoryCollectionRun::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepositoryCollectionRuns::route('/'),
            'view' => ViewRepositoryCollectionRun::route('/{record}'),
        ];
    }
}
