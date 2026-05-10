<?php

declare(strict_types=1);

namespace App\Domain\Scene;

/** @deprecated Usa App\Domain\Scene\Activity */
final class Scene extends Activity
{
    public static function fromArray(array $data): self
    {
        // Devuelve Activity pero como Scene (BC alias)
        $activity = Activity::fromArray($data);
        return new self(
            id: $activity->id,
            vaultId: $activity->vaultId,
            title: $activity->title,
            chapter: $activity->chapter,
            sceneNumber: $activity->sceneNumber,
            status: $activity->status,
            locationId: $activity->locationId,
            objective: $activity->objective,
            constraints: $activity->constraints,
            draft: $activity->draft,
            characters: $activity->characters,
            createdAt: $activity->createdAt,
            updatedAt: $activity->updatedAt,
        );
    }
}
