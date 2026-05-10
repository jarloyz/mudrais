<?php

namespace App\Infrastructure\Persistence\LegacySqlite;

use App\Application\Contracts\CharacterRepository;
use App\Application\Contracts\LegacyCanonImporter;
use App\Application\Contracts\LocationRepository;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Application\Contracts\VaultContextRepository;
use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\AvatarBullet;
use App\Domain\Catalog\AvatarTrait;
use App\Domain\Catalog\Context;
use App\Domain\Catalog\Location;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Domain\Scene\SceneCharacter;
use PDO;

class LegacyCanonSqliteImporter implements LegacyCanonImporter
{
    public function __construct(
        private readonly VaultContextRepository $vaultContextRepository,
        private readonly CharacterRepository $characterRepository,
        private readonly LocationRepository $locationRepository,
        private readonly SceneRepository $sceneRepository,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function import(string $sourcePath, string $vaultId, string $scope = 'canon_base+scenes'): array
    {
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'component' => 'legacy_sqlite_importer',
            'sourcePath' => $sourcePath,
            'vaultId' => $vaultId,
            'scope' => $scope,
        ]);

        $logger->info('Abriendo SQLite legacy');

        $pdo = new PDO('sqlite:'.$sourcePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->vaultContextRepository->saveVault(new Vault(
            id: $vaultId,
            name: $vaultId === 'vault_default' ? 'Vault Default' : "Vault {$vaultId}",
            status: 'active',
            description: 'Importado desde SQLite legacy',
        ));

        $contexts = $this->loadContexts($pdo);
        foreach ($contexts as $context) {
            $this->vaultContextRepository->saveContext($context);
        }
        $logger->info('Contexts importados', ['count' => count($contexts)]);

        $characters = $this->loadCharacters($pdo, $vaultId);
        foreach ($characters as $character) {
            $this->characterRepository->save($character);
        }
        $logger->info('Characters importados', ['count' => count($characters)]);

        $locations = $this->loadLocations($pdo, $vaultId);
        foreach ($locations as $location) {
            $this->locationRepository->save($location);
        }
        $logger->info('Locations importadas', ['count' => count($locations)]);

        $sceneCount = 0;
        if ($scope === 'canon_base+scenes') {
            $scenes = $this->loadScenes($pdo, $vaultId);
            foreach ($scenes as $scene) {
                $this->sceneRepository->save($scene);
            }
            $sceneCount = count($scenes);
            $logger->info('Scenes importadas', ['count' => $sceneCount]);
        } else {
            $logger->info('Importacion de escenas omitida por scope');
        }

        return [
            'vaults' => 1,
            'contexts' => count($contexts),
            'characters' => count($characters),
            'locations' => count($locations),
            'activities' => $sceneCount,
            'scope' => $scope,
        ];
    }

    /**
     * @return array<int, Context>
     */
    private function loadContexts(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, slug, label FROM contexts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $row): Context => new Context(
                id: (string) $row['slug'],
                label: (string) $row['label'],
                legacyContextId: isset($row['id']) ? (int) $row['id'] : null,
            ),
            $rows
        );
    }

    /**
     * @return array<int, Avatar>
     */
    private function loadCharacters(PDO $pdo, string $vaultId): array
    {
        $characters = [];
        $rows = $pdo->query('SELECT id, name, COALESCE(vault_id, \''.$vaultId.'\') AS vault_id FROM characters ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $characterId = (string) $row['id'];
            $traits = $this->loadCharacterTraits($pdo, $characterId);

            $characters[] = new Avatar(
                id: $characterId,
                vaultId: (string) $row['vault_id'],
                name: (string) $row['name'],
                traits: $traits,
            );
        }

        return $characters;
    }

    /**
     * @return array<int, AvatarTrait>
     */
    private function loadCharacterTraits(PDO $pdo, string $characterId): array
    {
        $statement = $pdo->prepare('
            SELECT id, context_id, key, title, sort_order
            FROM traits
            WHERE character_id = :character_id
            ORDER BY sort_order, id
        ');
        $statement->execute(['character_id' => $characterId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $traits = [];
        foreach ($rows as $row) {
            $traitId = (int) $row['id'];
            $traits[] = new AvatarTrait(
                key: (string) $row['key'],
                title: (string) $row['title'],
                sortOrder: (int) $row['sort_order'],
                contextId: $this->resolveContextId($pdo, $row['context_id'] ?? null),
                bullets: $this->loadTraitBullets($pdo, $traitId),
            );
        }

        return $traits;
    }

    /**
     * @return array<int, AvatarBullet>
     */
    private function loadTraitBullets(PDO $pdo, int $traitId): array
    {
        $statement = $pdo->prepare('
            SELECT id, parent_bullet_id, section, text, sort_order
            FROM bullets
            WHERE trait_id = :trait_id
            ORDER BY sort_order, id
        ');
        $statement->execute(['trait_id' => $traitId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $row): AvatarBullet => new AvatarBullet(
                body: (string) $row['text'],
                section: $row['section'] !== null ? (string) $row['section'] : null,
                sortOrder: (int) $row['sort_order'],
                legacyBulletId: isset($row['id']) ? (int) $row['id'] : null,
                parentLegacyBulletId: $row['parent_bullet_id'] !== null ? (int) $row['parent_bullet_id'] : null,
            ),
            $rows
        );
    }

    /**
     * @return array<int, Location>
     */
    private function loadLocations(PDO $pdo, string $vaultId): array
    {
        $rows = $pdo->query('SELECT id, name, context_id, COALESCE(vault_id, \''.$vaultId.'\') AS vault_id FROM locations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $row): Location => new Location(
                id: (string) $row['id'],
                vaultId: (string) $row['vault_id'],
                name: (string) $row['name'],
                contextId: $this->resolveContextId($pdo, $row['context_id'] ?? null),
            ),
            $rows
        );
    }

    /**
     * @return array<int, Activity>
     */
    private function loadScenes(PDO $pdo, string $vaultId): array
    {
        $rows = $pdo->query('
            SELECT id, title, chapter, scene, status, location_id, objective, constraints, draft, COALESCE(vault_id, \''.$vaultId.'\') AS vault_id
            FROM scenes
            ORDER BY chapter, scene, id
        ')->fetchAll(PDO::FETCH_ASSOC);

        $scenes = [];
        foreach ($rows as $row) {
            $sceneId = (string) $row['id'];
            $scenes[] = Activity::fromArray([
                'id' => $sceneId,
                'vaultId' => (string) $row['vault_id'],
                'title' => (string) ($row['title'] ?? ''),
                'chapter' => (int) ($row['chapter'] ?? 1),
                'sceneNumber' => (int) ($row['scene'] ?? 1),
                'status' => (string) ($row['status'] ?? 'draft'),
                'locationId' => $row['location_id'] !== null ? (string) $row['location_id'] : null,
                'objective' => $row['objective'] !== null ? (string) $row['objective'] : null,
                'constraints' => $row['constraints'] !== null ? (string) $row['constraints'] : null,
                'draft' => $row['draft'] !== null ? (string) $row['draft'] : '',
                'characters' => $this->loadSceneCharacters($pdo, $sceneId),
            ]);
        }

        return $scenes;
    }

    /**
     * @return array<int, SceneCharacter>
     */
    private function loadSceneCharacters(PDO $pdo, string $sceneId): array
    {
        $statement = $pdo->prepare('
            SELECT character_id, role
            FROM scene_characters
            WHERE scene_id = :scene_id
            ORDER BY character_id
        ');
        $statement->execute(['scene_id' => $sceneId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn (array $row): SceneCharacter => new SceneCharacter(
                characterId: (string) $row['character_id'],
                role: $row['role'] !== null ? (string) $row['role'] : null,
            ),
            $rows
        );
    }

    private function resolveContextId(PDO $pdo, mixed $legacyContextId): ?string
    {
        if ($legacyContextId === null || $legacyContextId === '') {
            return null;
        }

        $statement = $pdo->prepare('SELECT slug FROM contexts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int) $legacyContextId]);
        $slug = $statement->fetchColumn();

        return $slug !== false ? (string) $slug : null;
    }
}
