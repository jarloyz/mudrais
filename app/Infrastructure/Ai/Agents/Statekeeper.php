<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\CharacterRuntimeStatusRepository;
use App\Application\Contracts\StructuredLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;

class Statekeeper
{
    public function __construct(
        private readonly CharacterRuntimeStatusRepository $statusRepository,
        private readonly StructuredLogger $logger
    ) {}

    /**
     * Aplica las mutaciones (State Deltas) generadas por el Writer a la base de datos.
     * @param array<string, mixed> $mutations
     */
    public function applyMutations(array $mutations, string $sceneId, ?int $userId, array $context): void
    {
        if (empty($mutations)) {
            return;
        }

        $continuityId = (string) ($context['continuity_id'] ?? '');
        if ($continuityId === '') {
            $this->logger->warning('Statekeeper: No hay continuityId en el contexto. Saltando persistencia de runtime status.', [
                'sceneId' => $sceneId,
            ]);
            return;
        }

        $this->logger->info('Aplicando mutaciones de estado (Deltas)', [
            'sceneId' => $sceneId,
            'mutations' => $mutations,
            'userId' => $userId,
            'continuityId' => $continuityId,
        ]);

        DB::transaction(function () use ($mutations, $sceneId, $userId, $context, $continuityId) {
            $charactersInScene = $context['characters'] ?? [];
            $playerId = null;
            $runtimeStatus = [];

            foreach ($charactersInScene as $char) {
                if (($char['role'] ?? '') === 'player') {
                    $playerId = $char['id'];
                    $runtimeStatus = $char['profile']['runtimeStatus'] ?? [];
                    break;
                }
            }

            // Fallbacks legacy
            if (isset($mutations['state_changes'])) {
                $this->processLegacyMutations($mutations['state_changes'], $charactersInScene, $continuityId, $sceneId, $userId);
                return;
            }

            if (! $playerId) {
                $this->logger->warning('Statekeeper: No se encontró personaje jugador en la escena.');
                return;
            }

            // 1. Procesar status_modifiers (Deltas matemáticos)
            if (isset($mutations['status_modifiers']) && is_array($mutations['status_modifiers'])) {
                $rows = [];
                foreach ($mutations['status_modifiers'] as $key => $delta) {
                    if ((float)$delta == 0) continue;

                    $currentVal = 0;
                    $unit = 'level';
                    foreach ($runtimeStatus as $rs) {
                        if ($rs['status_key'] === $key) {
                            $currentVal = (float)($rs['status_value'] ?? 0);
                            $unit = $rs['unit'] ?? 'level';
                            break;
                        }
                    }

                    $rows[] = [
                        'character_id' => $playerId,
                        'status_key' => $key,
                        'status_value' => $currentVal + (float)$delta,
                        'unit' => $unit
                    ];
                }

                if (!empty($rows)) {
                    $this->statusRepository->upsertManyStatus([
                        'continuityId' => $continuityId,
                        'sceneId' => $sceneId,
                        'userId' => $userId,
                        'source' => 'writer_delta',
                        'rows' => $rows,
                    ]);
                }
            }

            // 2. Procesar status_tags (Arrays aditivos y sustractivos)
            $tagsAdd = is_array($mutations['tags_add'] ?? null) ? $mutations['tags_add'] : [];
            $tagsRemove = is_array($mutations['tags_remove'] ?? null) ? $mutations['tags_remove'] : [];

            if (!empty($tagsAdd) || !empty($tagsRemove)) {
                $currentTags = [];
                foreach ($runtimeStatus as $rs) {
                    if ($rs['status_key'] === 'status_tags') {
                        $val = $rs['status_text'] ?? '[]';
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $currentTags = $decoded;
                        } else {
                            $currentTags = array_map('trim', explode(',', $val));
                        }
                        break;
                    }
                }

                $newTags = array_values(array_diff(array_unique(array_merge($currentTags, $tagsAdd)), $tagsRemove));

                $this->statusRepository->upsertManyStatus([
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'userId' => $userId,
                    'source' => 'writer_delta',
                    'rows' => [[
                        'character_id' => $playerId,
                        'status_key' => 'status_tags',
                        'status_text' => json_encode($newTags, JSON_UNESCAPED_UNICODE),
                        'unit' => 'json_array'
                    ]],
                ]);
            }

            // 3. Procesar character_inventory (Adiciones)
            $inventoryAdd = is_array($mutations['inventory_add'] ?? null) ? $mutations['inventory_add'] : [];
            if (!empty($inventoryAdd)) {
                foreach ($inventoryAdd as $item) {
                    $name = $item['item_name'] ?? null;
                    $qty = (int)($item['quantity'] ?? 1);
                    $cat = $item['category'] ?? null;

                    if ($name && $qty > 0) {
                        $existing = DB::table('character_inventory')
                            ->where('character_id', $playerId)
                            ->where('item_name', $name)
                            ->first();

                        if ($existing) {
                            DB::table('character_inventory')
                                ->where('id', $existing->id)
                                ->increment('quantity', $qty);
                        } else {
                            DB::table('character_inventory')->insert([
                                'id' => (string) Uuid::v7(),
                                'character_id' => $playerId,
                                'item_name' => $name,
                                'category' => $cat,
                                'quantity' => $qty,
                                'is_quick_slot' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            // 4. Procesar character_inventory (Sustracciones)
            $inventoryRemove = is_array($mutations['inventory_remove'] ?? null) ? $mutations['inventory_remove'] : [];
            if (!empty($inventoryRemove)) {
                foreach ($inventoryRemove as $item) {
                    $name = $item['item_name'] ?? null;
                    $qty = (int)($item['quantity'] ?? 1);

                    if ($name && $qty > 0) {
                        $existing = DB::table('character_inventory')
                            ->where('character_id', $playerId)
                            ->where('item_name', $name)
                            ->first();

                        if ($existing) {
                            if ($existing->quantity <= $qty) {
                                DB::table('character_inventory')->where('id', $existing->id)->delete();
                            } else {
                                DB::table('character_inventory')->where('id', $existing->id)->decrement('quantity', $qty);
                            }
                        }
                    }
                }
            }

            // Auditoría
            DB::table('state_changes')->insert([
                'id' => (string) Uuid::v7(),
                'scene_id' => $sceneId,
                'user_id' => $userId,
                'payload' => json_encode($mutations, JSON_UNESCAPED_UNICODE),
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function processLegacyMutations(array $stateChanges, array $charactersInScene, string $continuityId, string $sceneId, ?string $userId): void
    {
        foreach ($stateChanges as $change) {
            $name = $change['character_name'] ?? null;
            if (! $name) continue;

            $characterId = null;
            foreach ($charactersInScene as $char) {
                if (strcasecmp($char['name'] ?? '', $name) === 0) {
                    $characterId = (string) $char['id'];
                    break;
                }
            }

            if (! $characterId) continue;

            $rows = [];
            if (isset($change['health'])) {
                $rows[] = ['character_id' => $characterId, 'status_key' => 'health', 'status_value' => (float)$change['health'], 'unit' => '%'];
            }
            if (isset($change['stamina'])) {
                $rows[] = ['character_id' => $characterId, 'status_key' => 'stamina', 'status_value' => (float)$change['stamina'], 'unit' => '%'];
            }
            if (isset($change['mood'])) {
                $rows[] = ['character_id' => $characterId, 'status_key' => 'mood', 'status_text' => (string)$change['mood']];
            }
            if (isset($change['status_tags']) && is_array($change['status_tags'])) {
                $rows[] = ['character_id' => $characterId, 'status_key' => 'status_tags', 'status_text' => implode(', ', $change['status_tags'])];
            }

            if (! empty($rows)) {
                $this->statusRepository->upsertManyStatus([
                    'continuityId' => $continuityId,
                    'sceneId' => $sceneId,
                    'userId' => $userId,
                    'source' => 'writer',
                    'rows' => $rows,
                ]);
            }
        }
    }
}
