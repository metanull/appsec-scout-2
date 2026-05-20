<?php

namespace App\Filament\Resources\SoftwareSystemLinkResource\RelationManagers;

use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('pivot.sort_order')
                ->numeric()
                ->minValue(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('source_id')->badge(),
                TextColumn::make('pivot.sort_order')->label('Order')->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }
}
