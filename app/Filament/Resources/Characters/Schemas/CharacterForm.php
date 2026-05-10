<?php

namespace App\Filament\Resources\Characters\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CharacterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('Avatar ID')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required(),
                Select::make('vault_id')
                    ->relationship('vault', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }
}
