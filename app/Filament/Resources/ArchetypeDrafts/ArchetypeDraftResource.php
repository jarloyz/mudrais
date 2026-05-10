<?php

namespace App\Filament\Resources\ArchetypeDrafts;

use App\Domains\Matchmaking\Models\ArchetypeDraft;
use App\Filament\Resources\ArchetypeDrafts\Pages\CreateArchetypeDraft;
use App\Filament\Resources\ArchetypeDrafts\Pages\ListArchetypeDrafts;
use App\Filament\Resources\ArchetypeDrafts\Pages\ViewArchetypeDraft;
use App\Filament\Resources\ArchetypeDrafts\Schemas\ArchetypeDraftForm;
use App\Filament\Resources\ArchetypeDrafts\Tables\ArchetypeDraftsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ArchetypeDraftResource extends Resource
{
    protected static ?string $model = ArchetypeDraft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static \UnitEnum|string|null $navigationGroup = 'Matchmaking';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'input_name';

    public static function form(Schema $schema): Schema
    {
        return ArchetypeDraftForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArchetypeDraftsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListArchetypeDrafts::route('/'),
            'create' => CreateArchetypeDraft::route('/create'),
            'view'   => ViewArchetypeDraft::route('/{record}'),
        ];
    }
}
