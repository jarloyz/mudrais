<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function make(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            CheckboxList::make('permissions')
                ->label('Permisos')
                ->relationship('permissions', 'name')
                ->columns(2)
                ->bulkToggleable()
                ->gridDirection('row'),
        ]);
    }
}
