<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\VaultContextRepository;
use App\Domain\Catalog\Context;
use App\Domain\Catalog\Vault;
use App\Infrastructure\Persistence\Eloquent\Models\ContextRecord;
use App\Infrastructure\Persistence\Eloquent\Models\VaultRecord;

class EloquentVaultContextRepository implements VaultContextRepository
{
    public function saveVault(Vault $vault): void
    {
        VaultRecord::query()->updateOrCreate(
            ['id' => $vault->id],
            [
                'name' => $vault->name,
                'status' => $vault->status,
                'description' => $vault->description,
            ]
        );
    }

    public function saveContext(Context $context): void
    {
        ContextRecord::query()->updateOrCreate(
            ['id' => $context->id],
            [
                'label' => $context->label,
                'legacy_context_id' => $context->legacyContextId,
            ]
        );
    }

    public function findVaultById(string $id): ?Vault
    {
        $record = VaultRecord::query()->find($id);

        return $record
            ? new Vault(
                id: $record->id,
                name: $record->name,
                status: $record->status,
                description: $record->description,
            )
            : null;
    }

    public function findContextById(string $id): ?Context
    {
        $record = ContextRecord::query()->find($id);

        return $record
            ? new Context(
                id: $record->id,
                label: $record->label,
                legacyContextId: $record->legacy_context_id,
            )
            : null;
    }
}
