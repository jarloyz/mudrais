<?php

namespace App\Application\Contracts;

use App\Domain\Catalog\Context;
use App\Domain\Catalog\Vault;

interface VaultContextRepository
{
    public function saveVault(Vault $vault): void;

    public function saveContext(Context $context): void;

    public function findVaultById(string $id): ?Vault;

    public function findContextById(string $id): ?Context;
}
