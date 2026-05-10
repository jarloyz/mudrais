<?php

namespace App\Filament\Resources\Activities;

use App\Domains\Narrative\Models\Activity;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use App\Filament\Resources\Activities\Pages;
use App\Filament\Resources\Activities\Schemas\ActivityForm;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bolt';
    protected static string | \UnitEnum | null $navigationGroup = 'Narrativa';
    protected static ?string $modelLabel = 'Actividad';
    protected static ?string $pluralModelLabel = 'Actividades';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ActivityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
            'create' => Pages\CreateActivity::route('/create'),
            'edit' => Pages\EditActivity::route('/{record}/edit'),
        ];
    }
}
