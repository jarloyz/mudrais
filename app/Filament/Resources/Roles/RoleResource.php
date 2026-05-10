<?php

namespace App\Filament\Resources\Roles;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use App\Filament\Resources\Roles\Pages;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';
    protected static string | \UnitEnum | null $navigationGroup = 'Sistema';
    protected static ?int $navigationSort = 11;
    protected static ?string $modelLabel = 'Rol';
    protected static ?string $pluralModelLabel = 'Roles';
    protected static ?string $slug = 'sistema/roles';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return RoleForm::make($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::make($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
