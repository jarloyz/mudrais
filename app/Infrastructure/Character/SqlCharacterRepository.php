<?php

declare(strict_types=1);

namespace App\Infrastructure\Character;

use App\Application\Ports\CharacterRepositoryInterface;
use App\Domains\Narrative\Models\Avatar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Uuid;

final class SqlCharacterRepository implements CharacterRepositoryInterface
{
    public function getCharacterProfile(string $characterId, ?int $contextId): array
    {
        $id = $this->text($characterId);
        if (empty($id)) {
            throw new \InvalidArgumentException("characterId es requerido");
        }

        $character = DB::table('avatars')
            ->select('id', 'name', 'public_facade', 'created_at', 'updated_at')
            ->where('id', $id)
            ->first();

        if (!$character) {
            throw new \RuntimeException("character no encontrado: {$id}");
        }

        $tags = $contextId === null
            ? DB::table('character_tags as ct')
                ->join('tags as t', 't.id', '=', 'ct.tag_id')
                ->select('t.name')
                ->where('ct.character_id', $id)
                ->whereNull('ct.context_id')
                ->orderBy('t.name')
                ->get()
            : DB::table('character_tags as ct')
                ->join('tags as t', 't.id', '=', 'ct.tag_id')
                ->select('t.name')
                ->where('ct.character_id', $id)
                ->where('ct.context_id', $contextId)
                ->orderBy('t.name')
                ->get();

        // Intentar leer de public_facade primero (Nueva arquitectura)
        if (!empty($character->public_facade)) {
            $traitsData = json_decode($character->public_facade, true);
            if (is_array($traitsData)) {
                // Filtrar por context_id si es necesario (el JSON puede contener varios contextos o el actual)
                // Por ahora asumimos que public_facade es el estado actual global.
                $traitList = array_map(function ($t) {
                    return [
                        'key' => $this->text($t['key'] ?? ''),
                        'title' => $this->text($t['title'] ?? ''),
                        'sort_order' => (int)($t['sort_order'] ?? 0),
                        'bullets' => array_map(function ($b) {
                            return [
                                'text' => $this->text($b['body'] ?? $b['text'] ?? ''),
                                'section' => $this->text($b['section'] ?? '') ?: null,
                                'sort_order' => (int)($b['sort_order'] ?? 0),
                            ];
                        }, $t['bullets'] ?? []),
                    ];
                }, $traitsData);

                return [
                    'id' => $this->text($character->id),
                    'name' => $this->text($character->name),
                    'context_id' => $contextId,
                    'tags' => $tags->pluck('name')->filter(fn($x) => !empty($x))->all(),
                    'traits' => $traitList,
                ];
            }
        }

        // Fallback a character_bullets (Legado)
        $bulletsQuery = $contextId === null
            ? DB::table('character_bullets')
                ->where('character_id', $id)
                ->whereNull('context_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
            : DB::table('character_bullets')
                ->where('character_id', $id)
                ->where('context_id', $contextId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

        $traitList = [];
        $grouped = collect($bulletsQuery)->groupBy(fn($b) => $this->text($b->trait_key) ?: 'general');
        $traitSort = 0;

        foreach ($grouped as $traitKey => $bullets) {
            $traitSort++;
            $traitTitle = ucfirst($traitKey);
            $trait = [
                'key' => $traitKey,
                'title' => $traitTitle,
                'sort_order' => $traitSort,
                'bullets' => [],
            ];
            foreach ($bullets as $bullet) {
                $trait['bullets'][] = [
                    'id' => (int)$bullet->id,
                    'section' => $this->text($bullet->section) ?: null,
                    'text' => $this->text($bullet->content),
                    'sort_order' => (int)$bullet->sort_order,
                    'parent_bullet_id' => $bullet->parent_bullet_id ? (int)$bullet->parent_bullet_id : null,
                ];
            }
            $traitList[] = $trait;
        }

        return [
            'id' => $this->text($character->id),
            'name' => $this->text($character->name),
            'context_id' => $contextId,
            'tags' => $tags->pluck('name')->filter(fn($x) => !empty($x))->all(),
            'traits' => $traitList,
        ];
    }

    public function upsertCharacterProfile(array $profile): array
    {
        $stats = [
            'character_upserted' => 0,
            'tags_written' => 0,
            'traits_upserted' => 0,
            'traits_deleted' => 0,
            'bullets_written' => 0,
            'character_bullets_written' => 0,
            'character_backgrounds_written' => 0,
        ];

        DB::transaction(function () use ($profile, &$stats) {
            $hasCharacterBullets = $this->tableExists('character_bullets');
            $hasCharacterBackgrounds = $this->tableExists('character_backgrounds');
            $hasPublicFacade = Schema::hasColumn('avatars', 'public_facade');

            // Preparar public_facade JSON
            $publicFacadeJson = null;
            if ($hasPublicFacade) {
                $publicFacadeJson = json_encode(array_map(function ($t) {
                    return [
                        'key' => $t['key'],
                        'title' => $t['title'],
                        'sort_order' => $t['sort_order'] ?? 0,
                        'bullets' => array_map(function ($b) {
                            return [
                                'body' => $b['text'],
                                'section' => $b['section'],
                                'sort_order' => $b['sort_order'],
                            ];
                        }, $t['bullets'] ?? []),
                    ];
                }, $profile['traits'] ?? []), JSON_UNESCAPED_UNICODE);
            }

            DB::table('avatars')->upsert(
                [
                    'id' => $profile['id'],
                    'name' => $profile['name'],
                    'vault_id' => $profile['vault_id'] ?? 'vault_default',
                    'public_facade' => $publicFacadeJson,
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                ['id'],
                ['name', 'vault_id', 'public_facade', 'updated_at']
            );
            $stats['character_upserted'] = 1;

            if ($profile['context_id'] === null) {
                DB::table('taggables')
                    ->where('taggable_id', $profile['id'])
                    ->where('taggable_type', Avatar::class)
                    ->whereNull('tag_context')
                    ->delete();
            } else {
                DB::table('taggables')
                    ->where('taggable_id', $profile['id'])
                    ->where('taggable_type', Avatar::class)
                    ->where('tag_context', (string)$profile['context_id'])
                    ->delete();
            }

            foreach ($profile['tags'] as $tag) {
                $slug = Str::slug($tag, '_');
                $tagId = DB::table('canonical_tags')->where('slug', $slug)->value('id');

                if (!$tagId) {
                    $tagId = (string) Uuid::v7();
                    DB::table('canonical_tags')->insert([
                        'id' => $tagId,
                        'slug' => $slug,
                        'name' => $tag,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($tagId) {
                    DB::table('taggables')->insert([
                        'id' => (string) Uuid::v7(),
                        'taggable_id' => $profile['id'],
                        'taggable_type' => Avatar::class,
                        'canonical_tag_id' => $tagId,
                        'tag_context' => $profile['context_id'] !== null ? (string)$profile['context_id'] : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $stats['tags_written']++;
                }
            }

            $derived = $this->_syncCharacterDerivedTables($profile, $hasCharacterBullets, $hasCharacterBackgrounds);
            $stats['character_bullets_written'] += $derived['bullets'] ?? 0;
            $stats['character_backgrounds_written'] += $derived['backgrounds'] ?? 0;

            $stats['traits_upserted'] = count($profile['traits'] ?? []);
            $stats['bullets_written'] = $stats['character_bullets_written'];
        });

        return [
            'characterId' => $profile['id'],
            'contextId' => $profile['context_id'],
            'stats' => $stats,
        ];
    }

    private function _syncCharacterDerivedTables(array $profile, bool $hasCharacterBullets, bool $hasCharacterBackgrounds): array
    {
        if (!$hasCharacterBullets && !$hasCharacterBackgrounds) {
            return ['bullets' => 0, 'backgrounds' => 0];
        }

        if ($profile['context_id'] === null) {
            if ($hasCharacterBackgrounds) {
                DB::table('character_backgrounds')
                    ->where('character_id', $profile['id'])
                    ->whereNull('context_id')
                    ->delete();
            }
            if ($hasCharacterBullets) {
                DB::table('character_bullets')
                    ->where('character_id', $profile['id'])
                    ->whereNull('context_id')
                    ->delete();
            }
        } else {
            if ($hasCharacterBackgrounds) {
                DB::table('character_backgrounds')
                    ->where('character_id', $profile['id'])
                    ->where('context_id', $profile['context_id'])
                    ->delete();
            }
            if ($hasCharacterBullets) {
                DB::table('character_bullets')
                    ->where('character_id', $profile['id'])
                    ->where('context_id', $profile['context_id'])
                    ->delete();
            }
        }

        $bulletsWritten = 0;
        $backgroundsWritten = 0;
        $globalSort = 0;
        $backgroundSort = 0;

        foreach ($profile['traits'] as $trait) {
            $traitKey = $this->text($trait['key']);
            $traitTitle = $this->text($trait['title']) ?: $traitKey;
            $insertedByIndex = [];
            $bullets = $trait['bullets'] ?? [];

            foreach ($bullets as $i => $bullet) {
                $section = $this->text($bullet['section']) ?: null;
                $content = $this->text($bullet['text']);
                if (empty($content)) {
                    continue;
                }
                $inferred = $this->inferBulletType([
                    'traitKey' => $traitKey,
                    'traitTitle' => $traitTitle,
                    'section' => $section,
                    'textValue' => $content,
                ]);

                $globalSort++;
                $insertedBulletId = (string) Uuid::v7();
                if ($hasCharacterBullets) {
                    $parentId = $insertedByIndex[(int)($bullet['parent_index'] ?? 0)] ?? null;
                    DB::table('character_bullets')->insert([
                        'id' => $insertedBulletId,
                        'character_id' => $profile['id'],
                        'context_id' => $profile['context_id'],
                        'trait_key' => $traitKey ?: null,
                        'section' => $section,
                        'parent_bullet_id' => $parentId,
                        'content' => $content,
                        'bullet_type' => $inferred['bulletType'],
                        'is_sexual' => $inferred['isSexual'] ? 1 : 0,
                        'sort_order' => $globalSort,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $insertedByIndex[$i + 1] = $insertedBulletId;
                    $bulletsWritten++;
                }

                if ($hasCharacterBackgrounds && ($inferred['isBackground'] || $inferred['isSexual'])) {
                    $backgroundSort++;
                    $category = $inferred['isSexual']
                        ? 'sexual'
                        : $this->inferBackgroundCategory([
                            'traitKey' => $traitKey,
                            'section' => $section,
                            'textValue' => $content,
                        ]);

                    DB::table('character_backgrounds')->insert([
                        'id' => (string) Uuid::v7(),
                        'character_id' => $profile['id'],
                        'context_id' => $profile['context_id'],
                        'category' => $category,
                        'title' => $section ?: $traitTitle ?: null,
                        'summary' => $content,
                        'detail' => null,
                        'is_sexual' => $inferred['isSexual'] ? 1 : 0,
                        'importance' => $inferred['isSexual'] ? 3 : 2,
                        'source_trait_key' => $traitKey ?: null,
                        'source_bullet_id' => $insertedBulletId,
                        'sort_order' => $backgroundSort,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $backgroundsWritten++;
                }
            }
        }

        return [
            'bullets' => $bulletsWritten,
            'backgrounds' => $backgroundsWritten,
        ];
    }

    private function inferBackgroundCategory(array $data): string
    {
        $scope = $this->normalizeToken("{$data['traitKey']} {$data['section']} {$data['textValue']}");
        if (preg_match('/\bsexo|sexual|sexuales|intim|fantasi|vagina|vulva|anal|oral|fetich|orgasm|coito|penetraci|pezon|pecho\b/', $scope)) return "sexual";
        if (preg_match('/\borigen|infancia|crianza|nacimiento\b/', $scope)) return "origin";
        if (preg_match('/\bfamilia|padre|madre|herman|hogar\b/', $scope)) return "family";
        if (preg_match('/\beducacion|escuela|universidad|estudio\b/', $scope)) return "education";
        if (preg_match('/\btrabajo|trayectoria|empleo|profesion|profesion\b/', $scope)) return "work";
        if (preg_match('/\bevento|hito|formativo|pasado\b/', $scope)) return "event";
        if (preg_match('/\btrauma|abuso|violencia|cicatriz\b/', $scope)) return "trauma";
        if (preg_match('/\brelacion|amistad|pareja|vinculo\b/', $scope)) return "relationship";
        return "general";
    }

    private function inferBulletType(array $data): array
    {
        $scope = $this->normalizeToken("{$data['traitKey']} {$data['traitTitle']} {$data['section']} {$data['textValue']}");
        $isSexual = preg_match('/\bsexo|sexual|sexuales|intim|fantasi|vagina|vulva|anal|oral|fetich|orgasm|coito|penetraci|pezon|pecho\b/', $scope);
        if ($isSexual) {
            return ['bulletType' => 'sexual', 'isSexual' => true, 'backgroundCategory' => 'sexual', 'isBackground' => true];
        }
        if (preg_match('/\bvoz|habla|tono|muletilla|acento\b/', $scope)) {
            return ['bulletType' => 'voice', 'isSexual' => false, 'backgroundCategory' => 'general', 'isBackground' => false];
        }
        if (preg_match('/\bfisic|cuerpo|apariencia|imagen|altura|piel|cabello|ojo\b/', $scope)) {
            return ['bulletType' => 'physical', 'isSexual' => false, 'backgroundCategory' => 'general', 'isBackground' => false];
        }
        if (preg_match('/\bhistoria|origen|familia|educacion|trabajo|trayectoria|trauma|evento|pasado\b/', $scope)) {
            return [
                'bulletType' => 'background',
                'isSexual' => false,
                'backgroundCategory' => $this->inferBackgroundCategory($data),
                'isBackground' => true,
            ];
        }
        return ['bulletType' => 'profile', 'isSexual' => false, 'backgroundCategory' => 'general', 'isBackground' => false];
    }

    private function normalizeToken(?string $value): string
    {
        $base = $value ?? "";
        $base = iconv('UTF-8', 'ASCII//TRANSLIT', $base); // Remove diacritics
        return strtolower(trim($base));
    }

    private function tableExists(string $tableName): bool
    {
        return DB::getSchemaBuilder()->hasTable($tableName);
    }

    private function text(?string $value): string
    {
        return trim($value ?? "");
    }
}
