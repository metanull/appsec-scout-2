<?php

namespace App\Filament\Resources\ErrorLogResource\Pages;

use App\Filament\Resources\ErrorLogResource;
use App\Models\ErrorLog;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListErrorLogs extends ListRecords
{
    protected static string $resource = ErrorLogResource::class;

    private const CHANNEL_LABELS = [
        'repository-collection' => 'Repository Collection',
        'static-analysis' => 'Static Analysis',
        'static-analysis-import' => 'Static Analysis Import',
        'sbom-import' => 'SBOM Import',
        'sync' => 'Sync',
        'database' => 'Application',
    ];

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')];

        foreach (self::CHANNEL_LABELS as $channel => $label) {
            $tabs[$channel] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('channel', $channel))
                ->badge(fn (): int => ErrorLog::query()->where('channel', $channel)->count());
        }

        return $tabs;
    }
}
