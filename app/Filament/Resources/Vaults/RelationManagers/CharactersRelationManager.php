<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Filament\Resources\Characters\Schemas\CharacterForm;
use App\Filament\Resources\Characters\Tables\CharactersTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;

class CharactersRelationManager extends RelationManager
{
    protected static string $relationship = 'characters';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return CharacterForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        $table = CharactersTable::configure($table);
        $table->headerActions([
            CreateAction::make(),
        ]);
        return $table;
    }
}
