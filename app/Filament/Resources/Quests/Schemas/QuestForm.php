<?php

namespace App\Filament\Resources\Quests\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class QuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vault_id')
                    ->relationship('vault', 'name')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('type')
                    ->required()
                    ->default('main'),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                Repeater::make('steps')
                    ->relationship()
                    ->label('Steps')
                    ->helperText('Cada step define una etapa visible y verificable de la quest.')
                    ->defaultItems(3)
                    ->reorderable(false)
                    ->collapsible()
                    ->schema([
                        TextInput::make('stage_number')
                            ->label('Stage')
                            ->required()
                            ->numeric()
                            ->step(10),
                        Textarea::make('description')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),
                        Toggle::make('is_optional')
                            ->label('Opcional')
                            ->default(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
