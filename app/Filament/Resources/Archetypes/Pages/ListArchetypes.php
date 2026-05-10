<?php

namespace App\Filament\Resources\Archetypes\Pages;

use App\Filament\Resources\ArchetypeDrafts\ArchetypeDraftResource;
use App\Filament\Resources\Archetypes\ArchetypeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListArchetypes extends ListRecords
{
    protected static string $resource = ArchetypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_draft')
                ->label('Nuevo Arquetipo (vía Draft)')
                ->url(fn (): string => '/app/archetype-drafts/create')
                ->icon('heroicon-o-sparkles'),
        ];
    }
}
