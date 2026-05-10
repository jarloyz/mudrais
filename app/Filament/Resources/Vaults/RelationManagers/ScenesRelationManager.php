<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Filament\Resources\Scenes\Schemas\SceneForm;
use App\Filament\Resources\Scenes\Tables\ScenesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;

class ScenesRelationManager extends RelationManager
{
    protected static string $relationship = 'scenes';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return SceneForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        $table = ScenesTable::configure($table);
        $table->headerActions([
            CreateAction::make(),
        ]);
        return $table;
    }
}
