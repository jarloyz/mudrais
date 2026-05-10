<?php

namespace App\Domain\Continuity;

final class ContinuityBranch
{
    public function __construct(
        public readonly string $id,
        public readonly string $vaultId,
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $parentContinuityId,
        public readonly ?string $description = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }
}
