<?php

namespace Tests\Feature\Catalog;

use App\Application\UseCases\ImportLegacyCanonUseCase;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentLocationRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Infrastructure\Persistence\LegacySqlite\LegacyCanonSqliteImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class LegacyCanonImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_canon_and_scenes_from_legacy_sqlite(): void
    {
        $legacyPath = $this->buildLegacyDatabase();
        $entries = [];
        $logger = new ArrayStructuredLogger($entries);

        $importer = new LegacyCanonSqliteImporter(
            new EloquentVaultContextRepository(),
            new EloquentCharacterRepository(),
            new EloquentLocationRepository(),
            new EloquentSceneRepository(),
            $logger,
        );

        $result = (new ImportLegacyCanonUseCase($importer, $logger))
            ->execute($legacyPath, 'vault_test', 'canon_base+scenes');

        $character = (new EloquentCharacterRepository())->findById('ana');
        $scene = (new EloquentSceneRepository())->findById('scene_1');
        $location = (new EloquentLocationRepository())->findById('casa');
        $vault = (new EloquentVaultContextRepository())->findVaultById('vault_test');
        $context = (new EloquentVaultContextRepository())->findContextById('modern');

        $this->assertSame([
            'vaults' => 1,
            'contexts' => 1,
            'characters' => 1,
            'locations' => 1,
            'activities' => 1,
            'scope' => 'canon_base+scenes',
        ], $result);

        $this->assertNotNull($vault);
        $this->assertSame('vault_test', $vault?->id);
        $this->assertNotNull($context);
        $this->assertSame('modern', $context?->id);
        $this->assertSame(1, $context?->legacyContextId);

        $this->assertNotNull($character);
        $this->assertSame('Ana', $character?->name);
        $this->assertCount(1, $character?->traits ?? []);
        $trait = $character->traits[0];
        $this->assertSame('voz', $trait->key);
        $this->assertSame('modern', $trait->contextId);
        $this->assertCount(2, $trait->bullets);
        $this->assertSame(1, $trait->bullets[0]->legacyBulletId);
        $this->assertSame(1, $trait->bullets[1]->parentLegacyBulletId);

        $this->assertNotNull($location);
        $this->assertSame('casa', $location?->id);
        $this->assertSame('modern', $location?->contextId);

        $this->assertNotNull($scene);
        $this->assertSame('casa', $scene?->locationId);
        $this->assertSame('objetivo breve', $scene?->objective);
        $this->assertSame('sin romper continuidad', $scene?->constraints);
        $this->assertSame("Borrador inicial", $scene?->draft);
        $this->assertCount(1, $scene?->characters ?? []);
        $this->assertSame('ana', $scene->characters[0]->characterId);
        $this->assertSame('protagonist', $scene->characters[0]->role);

        $this->assertTrue(collect($entries)->contains(fn (array $entry): bool => $entry['message'] === 'Inicio de importacion de canon legacy'));
        $this->assertTrue(collect($entries)->contains(fn (array $entry): bool => $entry['message'] === 'Importacion de canon legacy completada'));
    }

    private function buildLegacyDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy_sqlite_');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE contexts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL
            );
            CREATE TABLE characters (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                vault_id TEXT NOT NULL DEFAULT "vault_test"
            );
            CREATE TABLE traits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                character_id TEXT NOT NULL,
                context_id INTEGER NULL,
                key TEXT NOT NULL,
                title TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE bullets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trait_id INTEGER NOT NULL,
                parent_bullet_id INTEGER NULL,
                section TEXT NULL,
                text TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE locations (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                context_id INTEGER NULL,
                vault_id TEXT NOT NULL DEFAULT "vault_test"
            );
            CREATE TABLE scenes (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL DEFAULT "",
                chapter INTEGER NOT NULL DEFAULT 1,
                scene INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT "draft",
                location_id TEXT NULL,
                objective TEXT NULL,
                constraints TEXT NULL,
                draft TEXT NULL,
                vault_id TEXT NOT NULL DEFAULT "vault_test"
            );
            CREATE TABLE scene_characters (
                scene_id TEXT NOT NULL,
                character_id TEXT NOT NULL,
                role TEXT NULL,
                PRIMARY KEY(scene_id, character_id)
            );
        ');

        $pdo->exec("
            INSERT INTO contexts (id, slug, label) VALUES (1, 'modern', 'Moderno');
            INSERT INTO characters (id, name, vault_id) VALUES ('ana', 'Ana', 'vault_test');
            INSERT INTO traits (id, character_id, context_id, key, title, sort_order) VALUES (1, 'ana', 1, 'voz', 'Voz', 1);
            INSERT INTO bullets (id, trait_id, parent_bullet_id, section, text, sort_order) VALUES (1, 1, NULL, 'tono', 'Habla bajo', 1);
            INSERT INTO bullets (id, trait_id, parent_bullet_id, section, text, sort_order) VALUES (2, 1, 1, 'tono', 'Evita confrontar', 2);
            INSERT INTO locations (id, name, context_id, vault_id) VALUES ('casa', 'Casa', 1, 'vault_test');
            INSERT INTO scenes (id, title, chapter, scene, status, location_id, objective, constraints, draft, vault_id)
            VALUES ('scene_1', 'Llegada', 1, 1, 'draft', 'casa', 'objetivo breve', 'sin romper continuidad', 'Borrador inicial', 'vault_test');
            INSERT INTO scene_characters (scene_id, character_id, role) VALUES ('scene_1', 'ana', 'protagonist');
        ");

        return $path;
    }
}
