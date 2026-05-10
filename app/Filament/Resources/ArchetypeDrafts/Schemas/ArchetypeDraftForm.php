<?php

namespace App\Filament\Resources\ArchetypeDrafts\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ArchetypeDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('input_name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('input_text')
                    ->required()
                    ->rows(6),
            ]);
    }
}
