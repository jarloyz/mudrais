<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\VaultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVault extends EditRecord
{
    protected static string $resource = VaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
