<?php

namespace App\Filament\Resources\Archetypes;

use App\Domains\Matchmaking\Models\Archetype;
use App\Filament\Resources\Archetypes\Pages\CreateArchetype;
use App\Filament\Resources\Archetypes\Pages\EditArchetype;
use App\Filament\Resources\Archetypes\Pages\ListArchetypes;
use App\Filament\Resources\Archetypes\RelationManagers\ArchetypeEntityTypesRelationManager;
use App\Filament\Resources\Archetypes\RelationManagers\ArchetypeMutatorsRelationManager;
use App\Filament\Resources\Archetypes\RelationManagers\ArchetypePromptsRelationManager;
use App\Filament\Resources\Archetypes\Schemas\ArchetypeForm;
use App\Filament\Resources\Archetypes\Tables\ArchetypesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ArchetypeResource extends Resource
{
    protected static ?string $model = Archetype::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static \UnitEnum|string|null $navigationGroup = 'Matchmaking';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ArchetypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArchetypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ArchetypePromptsRelationManager::class,
            ArchetypeMutatorsRelationManager::class,
            ArchetypeEntityTypesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListArchetypes::route('/'),
            'edit'   => EditArchetype::route('/{record}/edit'),
        ];
    }
}
