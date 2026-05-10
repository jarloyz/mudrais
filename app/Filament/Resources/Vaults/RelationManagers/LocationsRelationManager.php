<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Locations\Tables\LocationsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        $table = LocationsTable::configure($table);
        $table->headerActions([
            CreateAction::make(),
        ]);
        return $table;
    }
}
