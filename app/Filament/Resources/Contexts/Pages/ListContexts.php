<?php

namespace App\Filament\Resources\Contexts\Pages;

use App\Filament\Resources\Contexts\ContextResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContexts extends ListRecords
{
    protected static string $resource = ContextResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
