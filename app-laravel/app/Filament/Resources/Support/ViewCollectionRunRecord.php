<?php

namespace App\Filament\Resources\Support;

use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Shared "View failures" header action for the two collection-run view
 * pages (ViewRepositoryCollectionRun, ViewStaticAnalysisRun) — identical
 * apart from which CollectionRunResource subclass supplies failuresUrl().
 */
abstract class ViewCollectionRunRecord extends ViewRecord
{
    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        /** @var class-string<CollectionRunResource> $resourceClass */
        $resourceClass = static::getResource();

        return [
            Action::make('viewFailures')
                ->label('View failures')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn (): bool => $resourceClass::failedCount($this->currentRecord()) > 0)
                ->url(fn (): string => $resourceClass::failuresUrl($this->currentRecord())),
        ];
    }

    private function currentRecord(): RepositoryCollectionRun|StaticAnalysisRun
    {
        /** @var RepositoryCollectionRun|StaticAnalysisRun $record */
        $record = $this->getRecord();

        return $record;
    }
}
