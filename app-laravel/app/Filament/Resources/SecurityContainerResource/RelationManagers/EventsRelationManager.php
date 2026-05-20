<?php

namespace App\Filament\Resources\SecurityContainerResource\RelationManagers;

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
                TextColumn::make('title')->wrap(),
                TextColumn::make('last_seen_at')->since(),
            ])
            ->paginated([10, 25, 50]);
    }
}
