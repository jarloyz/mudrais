<?php

namespace App\Application\UseCases;

use App\Application\Contracts\LocationRepository;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Catalog\Location;
use App\Infrastructure\Persistence\Eloquent\Models\LocationRecord;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use App\Models\Vault;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateVaultStarterPackUseCase
{
    public function __construct(
        private LocationRepository $locationRepository,
        private CreateSceneBootstrapUseCase $createSceneBootstrapUseCase,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Vault $vault, ?string $userId = null): array
    {
        if (! $vault->exists) {
            throw new InvalidArgumentException('El vault debe existir antes de crear el starter pack.');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'create_vault_starter_pack',
            'vaultId' => (string) $vault->id,
            'userId' => $userId,
        ]);
        $logger->info('Inicio de starter pack de vault');

        if (
            LocationRecord::query()->where('vault_id', $vault->id)->exists()
            || SceneRecord::query()->where('vault_id', $vault->id)->exists()
            || \App\Models\Quest::query()->where('vault_id', $vault->id)->exists()
        ) {
            $logger->info('Starter pack omitido porque el vault ya tiene contenido base');

            return [
                'created' => false,
                'reason' => 'already_seeded',
            ];
        }

        $locationId = $this->uniqueLocationId((string) $vault->id, 'inicio');
        $locationName = $this->deriveLocationName($vault);
        $this->locationRepository->save(new Location(
            id: $locationId,
            vaultId: (string) $vault->id,
            name: $locationName,
        ));

        $bootstrap = $this->createSceneBootstrapUseCase->execute([
            'scene_id' => $this->uniqueSceneId((string) $vault->id, 'escena_inicial'),
            'vault_id' => (string) $vault->id,
            'location_id' => $locationId,
            'title' => 'Escena inicial',
            'quest_prompt' => $this->buildQuestPrompt($vault, $locationName),
            'user_id' => $userId,
        ]);

        $logger->info('Starter pack de vault completado', [
            'locationId' => $locationId,
            'sceneId' => $bootstrap['scene']['id'] ?? null,
            'questId' => $bootstrap['quest']['questId'] ?? null,
        ]);

        return [
            'created' => true,
            'location' => [
                'id' => $locationId,
                'name' => $locationName,
            ],
            'bootstrap' => $bootstrap,
        ];
    }

    private function deriveLocationName(Vault $vault): string
    {
        $name = trim((string) $vault->name);

        return $name !== '' ? "Inicio de {$name}" : 'Punto de inicio';
    }

    private function buildQuestPrompt(Vault $vault, string $locationName): string
    {
        $name = trim((string) $vault->name);
        $description = trim((string) ($vault->description ?? ''));

        $parts = array_filter([
            $name !== '' ? "Vault: {$name}." : null,
            $description !== '' ? "Premisa: {$description}." : null,
            "La primera escena ocurre en {$locationName}.",
            'Genera una quest inicial simple pero utilizable para arrancar la historia con tension y objetivo inmediato.',
        ]);

        return implode(' ', $parts);
    }

    private function uniqueLocationId(string $vaultId, string $seed): string
    {
        $base = $this->slug($seed);
        $candidate = $base;
        $suffix = 1;

        while (LocationRecord::query()->where('vault_id', $vaultId)->where('id', $candidate)->exists()) {
            $suffix++;
            $candidate = "{$base}_{$suffix}";
        }

        return $candidate;
    }

    private function uniqueSceneId(string $vaultId, string $seed): string
    {
        $base = $this->slug($seed);
        $candidate = $base;
        $suffix = 1;

        while (SceneRecord::query()->where('vault_id', $vaultId)->where('id', $candidate)->exists()) {
            $suffix++;
            $candidate = "{$base}_{$suffix}";
        }

        return $candidate;
    }

    private function slug(string $value): string
    {
        $slug = Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();

        return $slug !== '' ? $slug : 'inicio';
    }
}
