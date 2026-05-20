<?php

namespace App\Filament\Resources\SoftwareSystemResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContainersRelationManager extends RelationManager
{
    protected static string $relationship = 'containers';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('source_container_id')->label('Source container'),
                TextColumn::make('last_seen_at')->since(),
            ])
            ->paginated([10, 25, 50]);
    }
}
