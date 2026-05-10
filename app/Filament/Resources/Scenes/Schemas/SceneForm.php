<?php

namespace App\Filament\Resources\Scenes\Schemas;

use App\Domains\Matchmaking\Enums\ActivityStatus;
use Filament\Schemas\Schema;

class SceneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Select::make('vault_id')
                    ->relationship('vault', 'name')
                    ->required(),
                \Filament\Schemas\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                \Filament\Schemas\Components\Select::make('status')
                    ->options(ActivityStatus::options())
                    ->required()
                    ->default(ActivityStatus::PENDING->value),
                \Filament\Schemas\Components\Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable(),
                \Filament\Schemas\Components\Textarea::make('objective')
                    ->columnSpanFull(),
            ]);
    }
}
