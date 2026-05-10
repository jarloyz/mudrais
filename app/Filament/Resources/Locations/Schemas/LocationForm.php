<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('Location ID')
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('vault_id')
                    ->relationship('vault', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('context_id')
                    ->numeric(),
            ]);
    }
}
