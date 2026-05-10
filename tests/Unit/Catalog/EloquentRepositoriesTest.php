<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\AvatarBullet;
use App\Domain\Catalog\AvatarTrait;
use App\Domain\Catalog\Context;
use App\Domain\Catalog\Location;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Domain\Scene\SceneCharacter;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentLocationRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_aggregate_roundtrips_without_losing_hierarchy(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_a', 'Vault A'));
        $vaultRepository->saveContext(new Context('modern', 'Moderno', 1));

        $repository = new EloquentCharacterRepository();
        $repository->save(new Avatar(
            id: 'ana',
            vaultId: 'vault_a',
            name: 'Ana',
            traits: [
                new AvatarTrait(
                    key: 'voz',
                    title: 'Voz',
                    sortOrder: 1,
                    contextId: 'modern',
                    bullets: [
                        new AvatarBullet('Habla bajo', 'tono', 1, 10, null),
                        new AvatarBullet('Evita confrontar', 'tono', 2, 11, 10),
                    ],
                ),
            ],
        ));

        $character = $repository->findById('ana');

        $this->assertNotNull($character);
        $this->assertSame('Ana', $character?->name);
        $this->assertCount(1, $character?->traits ?? []);
        $this->assertSame('modern', $character->traits[0]->contextId);
        $this->assertCount(2, $character->traits[0]->bullets);
        $this->assertSame(10, $character->traits[0]->bullets[0]->legacyBulletId);
        $this->assertSame(10, $character->traits[0]->bullets[1]->parentLegacyBulletId);
    }

    public function test_scene_roundtrips_with_location_and_characters(): void
    {
        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_b', 'Vault B'));

        (new EloquentCharacterRepository())->save(new Avatar(
            id: 'ana',
            vaultId: 'vault_b',
            name: 'Ana',
        ));

        (new EloquentLocationRepository())->save(new Location(
            id: 'cocina',
            vaultId: 'vault_b',
            name: 'Cocina',
        ));

        $repository = new EloquentSceneRepository();
        $repository->save(Activity::fromArray([
            'id' => 'scene_2',
            'vaultId' => 'vault_b',
            'title' => 'Cena',
            'chapter' => 2,
            'sceneNumber' => 3,
            'status' => 'draft',
            'locationId' => 'cocina',
            'objective' => null,
            'constraints' => null,
            'draft' => 'draft',
            'characters' => [
                new SceneCharacter('ana', 'protagonist'),
            ],
        ]));

        $scene = $repository->findById('scene_2');

        $this->assertNotNull($scene);
        $this->assertSame('cocina', $scene?->locationId);
        $this->assertNull($scene?->objective);
        $this->assertNull($scene?->constraints);
        $this->assertSame('draft', $scene?->draft);
        $this->assertCount(1, $scene?->characters ?? []);
        $this->assertSame('ana', $scene->characters[0]->characterId);
    }
}
