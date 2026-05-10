<?php

namespace App\Filament\Resources\Matchmaking;

use App\Filament\Resources\Matchmaking\Pages\ListMatchmakingVaults;
use App\Filament\Resources\Matchmaking\Tables\MatchmakingVaultsTable;
use App\Models\Vault;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MatchmakingVaultResource extends Resource
{
    protected static ?string $model = Vault::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static \UnitEnum|string|null $navigationGroup = 'Matchmaking';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Vaults';

    // Slug propio para no colisionar con VaultResource
    protected static ?string $slug = 'matchmaking-vaults';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return MatchmakingVaultsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatchmakingVaults::route('/'),
        ];
    }

    // Solo lectura: sin create ni edit
    public static function canCreate(): bool
    {
        return false;
    }
}
