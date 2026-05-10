<?php

namespace App\Filament\Resources\Users;

use App\Models\User;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use App\Filament\Resources\Users\Pages;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\RelationManagers\UserProfileRelationManager;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';
    protected static string | \UnitEnum | null $navigationGroup = 'Sistema';
    protected static ?int $navigationSort = 10;
    protected static ?string $modelLabel = 'Usuario';
    protected static ?string $pluralModelLabel = 'Usuarios';
    protected static ?string $slug = 'sistema/usuarios';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return UserForm::make($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::make($table);
    }

    public static function getRelations(): array
    {
        return [
            UserProfileRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
