<?php

namespace App\Filament\Resources\StaticAnalysisRunResource\Pages;

use App\Filament\Resources\StaticAnalysisRunResource;
use App\Models\StaticAnalysisRun;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewStaticAnalysisRun extends ViewRecord
{
    protected static string $resource = StaticAnalysisRunResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewFailures')
                ->label('View failures')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn (): bool => $this->failedCount() > 0)
                ->url(fn (): string => StaticAnalysisRunResource::failuresUrl($this->currentRecord())),
        ];
    }

    private function failedCount(): int
    {
        $counts = $this->currentRecord()->getAttribute('counts_json');

        return is_array($counts) ? (int) ($counts['repositories_failed'] ?? 0) : 0;
    }

    private function currentRecord(): StaticAnalysisRun
    {
        /** @var StaticAnalysisRun $record */
        $record = $this->getRecord();

        return $record;
    }
}
