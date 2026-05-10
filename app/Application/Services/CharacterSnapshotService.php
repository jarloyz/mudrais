<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Contracts\StructuredLogger;
use App\Models\Avatar;
use App\Models\CharacterInstance;
use App\Models\CharacterInventory;
use App\Models\CharacterBackground;
use App\Models\CharacterBullet;
use App\Models\Activity;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Servicio de Instantáneas de Personaje.
 *
 * Congela el estado de un personaje al entrar a una escena para que las
 * ediciones posteriores en el Baúl no rompan partidas en curso.
 */
final class CharacterSnapshotService
{
    public function __construct(
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Crea o actualiza la instantánea de un personaje en una escena.
     *
     * @return array{instance: CharacterInstance, created: bool}
     */
    public function snapshot(string $sceneId, string $characterId): array
    {
        $sceneId = trim($sceneId);
        $characterId = trim($characterId);

        if ($sceneId === '') {
            throw new InvalidArgumentException('sceneId es requerido.');
        }
        if ($characterId === '') {
            throw new InvalidArgumentException('characterId es requerido.');
        }

        $scene = Activity::query()->find($sceneId);
        if (! $scene instanceof Activity) {
            throw new InvalidArgumentException("Escena no encontrada: {$sceneId}");
        }

        $character = Avatar::query()->find($characterId);
        if (! $character instanceof Avatar) {
            throw new InvalidArgumentException("Personaje no encontrado: {$characterId}");
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'service' => 'character_snapshot',
            'sceneId' => $sceneId,
            'characterId' => $characterId,
        ]);

        $logger->info('Construyendo snapshot de personaje');

        $snapshotData = $this->buildSnapshotData($character);

        $existing = CharacterInstance::query()
            ->where('activity_id', $sceneId)
            ->where('avatar_id', $characterId)
            ->first();

        $created = false;

        if ($existing instanceof CharacterInstance) {
            // Re-snapshot: incrementar versión para trazabilidad
            $existing->update([
                'snapshot_data' => $snapshotData,
                'version' => $existing->version + 1,
                'snapshotted_at' => now(),
            ]);
            $instance = $existing->fresh();

            $logger->info('Snapshot actualizado', ['version' => $instance->version]);
        } else {
            $instance = CharacterInstance::query()->create([
                'activity_id' => $sceneId,
                'avatar_id' => $characterId,
                'snapshot_data' => $snapshotData,
                'version' => 1,
                'snapshotted_at' => now(),
            ]);
            $created = true;

            $logger->info('Snapshot creado', ['version' => 1]);
        }

        return [
            'instance' => $instance,
            'created' => $created,
        ];
    }

    /**
     * Crea snapshots para todos los personajes actualmente en la escena.
     *
     * @return array<int, array{characterId: string, created: bool}>
     */
    public function snapshotAll(string $sceneId): array
    {
        $sceneId = trim($sceneId);

        $characterIds = \Illuminate\Support\Facades\DB::table('activity_members')
            ->where('activity_id', $sceneId)
            ->pluck('avatar_id')
            ->all();

        $results = [];

        foreach ($characterIds as $characterId) {
            $result = $this->snapshot($sceneId, (string) $characterId);
            $results[] = [
                'characterId' => $characterId,
                'created' => $result['created'],
            ];
        }

        return $results;
    }

    /**
     * Recupera el snapshot de un personaje en una escena.
     * Retorna null si no existe instantánea.
     */
    public function getSnapshot(string $sceneId, string $characterId): ?CharacterInstance
    {
        return CharacterInstance::query()
            ->where('activity_id', $sceneId)
            ->where('avatar_id', $characterId)
            ->first();
    }

    /**
     * Construye el array de datos del snapshot a partir del estado actual del personaje.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshotData(Avatar $character): array
    {
        // Datos base
        $base = [
            'character_id' => $character->id,
            'name' => $character->name,
            'vault_id' => $character->vault_id,
        ];

        // public_facade existe si la migración cleanup_character_traits fue aplicada
        if (Schema::hasColumn('avatars', 'public_facade')) {
            $base['public_facade'] = $character->public_facade ?? null;
        }

        // Inventario
        $inventory = CharacterInventory::query()
            ->where('character_id', $character->id)
            ->get(['item_name', 'category', 'quantity', 'is_quick_slot'])
            ->map(static fn (CharacterInventory $item): array => [
                'item_name' => $item->item_name,
                'category' => $item->category,
                'quantity' => $item->quantity,
                'is_quick_slot' => (bool) $item->is_quick_slot,
            ])
            ->all();

        // Bullets de perfil (no sexuales)
        $bullets = [];
        if (Schema::hasTable('character_bullets')) {
            $bullets = CharacterBullet::query()
                ->where('character_id', $character->id)
                ->where('is_sexual', false)
                ->orderBy('sort_order')
                ->get(['section', 'trait_key', 'content', 'bullet_type', 'sort_order'])
                ->map(static fn (CharacterBullet $b): array => [
                    'section' => $b->section,
                    'trait_key' => $b->trait_key,
                    'content' => $b->content,
                    'bullet_type' => $b->bullet_type,
                    'sort_order' => $b->sort_order,
                ])
                ->all();
        }

        // Backgrounds relevantes (no sexuales, importancia >= 2)
        $backgrounds = [];
        if (Schema::hasTable('character_backgrounds')) {
            $backgrounds = CharacterBackground::query()
                ->where('character_id', $character->id)
                ->where('is_sexual', false)
                ->where('importance', '>=', 2)
                ->orderByDesc('importance')
                ->orderBy('sort_order')
                ->get(['category', 'title', 'summary', 'importance'])
                ->map(static fn (CharacterBackground $bg): array => [
                    'category' => $bg->category,
                    'title' => $bg->title,
                    'summary' => $bg->summary,
                    'importance' => $bg->importance,
                ])
                ->all();
        }

        // Stats: se inicia vacío; el Statekeeper los pobla durante la partida
        return array_merge($base, [
            'inventory' => $inventory,
            'bullets' => $bullets,
            'backgrounds' => $backgrounds,
            'stats' => [],
            'snapshot_version_note' => 'Congelado al entrar a la escena. Ediciones posteriores en el Baúl no afectan esta instantánea.',
        ]);
    }
}
