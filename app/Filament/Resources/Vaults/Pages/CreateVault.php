<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\VaultResource;
use App\Application\UseCases\CreateVaultStarterPackUseCase;
use Filament\Resources\Pages\CreateRecord;

class CreateVault extends CreateRecord
{
    protected static string $resource = VaultResource::class;

    protected function afterCreate(): void
    {
        app(CreateVaultStarterPackUseCase::class)->execute(
            $this->record,
            auth()->id(),
        );
    }
}
