<?php

namespace App\Filament\Resources\SoftwareSystemResource\RelationManagers;

use App\Filament\Resources\SecurityEventResource;
use App\Models\SecurityEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('severity')->badge(),
                TextColumn::make('state')->badge(),
                TextColumn::make('type')->badge(),
                TextColumn::make('title')->wrap(),
                TextColumn::make('last_seen_at')->since(),
            ])
            ->recordUrl(fn (SecurityEvent $record): string => SecurityEventResource::getUrl('view', ['record' => $record]))
            ->paginated([10, 25, 50]);
    }
}
