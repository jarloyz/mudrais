<?php

namespace App\Filament\Resources\Vaults\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class VaultForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('Vault ID')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required(),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'archived' => 'Archived',
                    ])
                    ->required()
                    ->default('active'),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                TagsInput::make('world_notes')
                    ->separator('|||') // Since it might contain long sentences, it's better to use a custom separator or use Repeater instead, but TagsInput is fine. Let's use TagsInput for simpler arrays.
                    ->columnSpanFull(),
                TagsInput::make('agent_instructions')
                    ->separator('|||')
                    ->columnSpanFull(),
            ]);
    }
}
