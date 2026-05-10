<?php

namespace App\Filament\Resources\Vaults;

use App\Filament\Resources\Vaults\Pages\CreateVault;
use App\Filament\Resources\Vaults\Pages\EditVault;
use App\Filament\Resources\Vaults\Pages\ListVaults;
use App\Filament\Resources\Vaults\Schemas\VaultForm;
use App\Filament\Resources\Vaults\Tables\VaultsTable;
use App\Models\Vault;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VaultResource extends Resource
{
    protected static ?string $model = Vault::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return VaultForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VaultsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\Vaults\RelationManagers\LocationsRelationManager::class,
            \App\Filament\Resources\Vaults\RelationManagers\CharactersRelationManager::class,
            \App\Filament\Resources\Vaults\RelationManagers\QuestsRelationManager::class,
            \App\Filament\Resources\Vaults\RelationManagers\ScenesRelationManager::class,
            \App\Filament\Resources\Vaults\RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVaults::route('/'),
            'create' => CreateVault::route('/create'),
            'edit' => EditVault::route('/{record}/edit'),
        ];
    }
}
