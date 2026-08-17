<?php

namespace App\Filament\Resources\RepositoryCollectionRunResource\Pages;

use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Models\RepositoryCollectionRun;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewRepositoryCollectionRun extends ViewRecord
{
    protected static string $resource = RepositoryCollectionRunResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewFailures')
                ->label('View failures')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn (): bool => $this->failedCount() > 0)
                ->url(fn (): string => RepositoryCollectionRunResource::failuresUrl($this->currentRecord())),
        ];
    }

    private function failedCount(): int
    {
        $counts = $this->currentRecord()->getAttribute('counts_json');

        return is_array($counts) ? (int) ($counts['repositories_failed'] ?? 0) : 0;
    }

    private function currentRecord(): RepositoryCollectionRun
    {
        /** @var RepositoryCollectionRun $record */
        $record = $this->getRecord();

        return $record;
    }
}
