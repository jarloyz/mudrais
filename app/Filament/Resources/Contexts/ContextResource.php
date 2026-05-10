<?php

namespace App\Filament\Resources\Contexts;

use App\Domains\Narrative\Models\Avatar;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use App\Filament\Resources\Contexts\Pages;
use App\Filament\Resources\Contexts\Schemas\ContextForm;
use App\Filament\Resources\Contexts\Tables\ContextsTable;

class ContextResource extends Resource
{
    protected static ?string $model = Avatar::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-circle';
    protected static string | \UnitEnum | null $navigationGroup = 'Narrativa';
    protected static ?string $modelLabel = 'Contexto';
    protected static ?string $pluralModelLabel = 'Contextos';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ContextForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContextsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContexts::route('/'),
            'create' => Pages\CreateContext::route('/create'),
            'edit' => Pages\EditContext::route('/{record}/edit'),
        ];
    }
}
