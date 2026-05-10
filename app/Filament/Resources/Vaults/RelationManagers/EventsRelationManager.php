<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Tables\EventsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        $table = EventsTable::configure($table);
        $table->headerActions([
            CreateAction::make(),
        ]);
        return $table;
    }
}
