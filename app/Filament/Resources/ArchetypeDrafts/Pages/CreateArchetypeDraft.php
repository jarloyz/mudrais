<?php

namespace App\Filament\Resources\ArchetypeDrafts\Pages;

use App\Filament\Resources\ArchetypeDrafts\ArchetypeDraftResource;
use App\Jobs\ProcessArchetypeDraftJob;
use Filament\Resources\Pages\CreateRecord;

class CreateArchetypeDraft extends CreateRecord
{
    protected static string $resource = ArchetypeDraftResource::class;

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()
            ->label('Procesar');
    }

    protected function getCreateAnotherFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateAnotherFormAction()->hidden();
    }

    protected function afterCreate(): void
    {
        ProcessArchetypeDraftJob::dispatch($this->record->id);
    }
}
