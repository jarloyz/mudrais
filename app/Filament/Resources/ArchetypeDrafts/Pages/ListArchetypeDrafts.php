<?php

namespace App\Filament\Resources\ArchetypeDrafts\Pages;

use App\Filament\Resources\ArchetypeDrafts\ArchetypeDraftResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListArchetypeDrafts extends ListRecords
{
    protected static string $resource = ArchetypeDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
