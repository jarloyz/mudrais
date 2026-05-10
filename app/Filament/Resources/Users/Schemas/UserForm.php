<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function make(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('password')
                ->label('Contraseña')
                ->password()
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $context): bool => $context === 'create')
                ->maxLength(255),
            Select::make('roles')
                ->label('Roles')
                ->multiple()
                ->relationship('roles', 'name')
                ->preload(),
        ]);
    }
}
