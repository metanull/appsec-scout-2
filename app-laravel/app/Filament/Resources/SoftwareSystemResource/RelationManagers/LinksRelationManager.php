<?php

namespace App\Filament\Resources\SoftwareSystemResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinksRelationManager extends RelationManager
{
    protected static string $relationship = 'links';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('description')->limit(80),
                TextColumn::make('pivot.sort_order')->label('Order'),
            ])
            ->paginated([10, 25, 50]);
    }
}
