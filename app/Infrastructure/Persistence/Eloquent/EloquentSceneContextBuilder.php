<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\CharacterRuntimeStatusRepository;
use App\Application\Contracts\ContinuityQuestStatusRepository;
use App\Models\CharacterInstance;
use App\Models\SceneActiveContinuity;
use App\Application\Contracts\SceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;

class EloquentSceneContextBuilder implements SceneContextBuilder
{
    public function __construct(
        private readonly ?CharacterRuntimeStatusRepository $characterRuntimeStatusRepository = null,
        private readonly ?ContinuityQuestStatusRepository $continuityQuestStatusRepository = null,
    ) {
    }

    public function build(string $sceneId, ?string $continuityId = null, ?string $userId = null): array
    {
        // Los traits de personaje se almacenan ahora en public_facade (JSON),
        // la tabla character_traits fue eliminada por la migración cleanup_character_traits.
        $withRelations = ['location', 'characters'];

        $scene = SceneRecord::query()
            ->with($withRelations)
            ->findOrFail($sceneId);

        $resolvedContinuityId = $continuityId ?: SceneActiveContinuity::query()->where('activity_id', $sceneId)->value('continuity_id');

        $characterIds = $scene->characters->pluck('id')->values()->all();

        $runtimeByCharacter = ($this->characterRuntimeStatusRepository && $resolvedContinuityId)
            ? $this->characterRuntimeStatusRepository->listForSceneContext($resolvedContinuityId, $sceneId, $userId, $characterIds)
            : [];

        $questStatuses = ($this->continuityQuestStatusRepository && $resolvedContinuityId)
            ? $this->continuityQuestStatusRepository->listForSceneContext($resolvedContinuityId, (string) $scene->vault_id)
            : [];

        // Cargar todos los snapshots disponibles para esta escena en una sola query
        $snapshots = CharacterInstance::query()
            ->where('activity_id', $sceneId)
            ->whereIn('avatar_id', $characterIds)
            ->get()
            ->keyBy('avatar_id');

        return [
            'location' => $scene->location
                ? [
                    'id' => $scene->location->id,
                    'name' => $scene->location->name,
                    'context_id' => $scene->location->context_id,
                ]
                : null,
            'objective' => $scene->objective,
            'constraints' => $scene->constraints,
            'draft' => $scene->draft,
            'characters' => $scene->characters->map(function ($character) use ($runtimeByCharacter, $snapshots): array {
                /** @var CharacterInstance|null $snapshot */
                $snapshot = $snapshots->get($character->id);

                if ($snapshot instanceof CharacterInstance) {
                    // Preferir Memoria de Escena (snapshot) sobre el Baúl
                    return $this->buildCharacterFromSnapshot($character, $snapshot, $runtimeByCharacter);
                }

                // Fallback: datos base del Baúl (comportamiento anterior)
                return $this->buildCharacterFromVault($character, $runtimeByCharacter);
            })->values()->all(),
            'quests' => $questStatuses,
            'events' => [],
            'stateChanges' => [],
            'historySummary' => null,
        ];
    }

    /**
     * Construye el perfil del personaje desde su snapshot (Memoria de Escena).
     * Garantiza que ediciones posteriores en el Baúl no afecten la partida en curso.
     */
    private function buildCharacterFromSnapshot(mixed $character, CharacterInstance $snapshot, array $runtimeByCharacter): array
    {
        $data = $snapshot->snapshot_data;
        $bullets = $data['bullets'] ?? [];
        $backgrounds = $data['backgrounds'] ?? [];

        // Agrupar bullets por sección para mantener la misma estructura que el Baúl
        $traitsBySection = [];
        foreach ($bullets as $bullet) {
            $section = $bullet['section'] ?? 'perfil';
            $traitsBySection[$section][] = $bullet['content'] ?? '';
        }

        $traits = array_map(
            static fn (string $key, array $contents): array => [
                'key' => $key,
                'title' => ucfirst($key),
                'bullets' => $contents,
            ],
            array_keys($traitsBySection),
            array_values($traitsBySection),
        );

        return [
            'id' => $character->id,
            'name' => $data['name'] ?? $character->name,
            'role' => $character->pivot->role,
            'public_facade' => $data['public_facade'] ?? null,
            'snapshot_version' => $snapshot->version,
            'inventory' => $snapshot->inventory(),
            'profile' => [
                'traits' => $traits,
                'backgrounds' => array_map(
                    static fn (array $bg): array => [
                        'category' => $bg['category'] ?? '',
                        'title' => $bg['title'] ?? '',
                        'summary' => $bg['summary'] ?? '',
                        'importance' => $bg['importance'] ?? 1,
                    ],
                    $backgrounds,
                ),
                'triggers' => [],
                'runtimeStatus' => $runtimeByCharacter[$character->id] ?? [],
                'stats' => $data['stats'] ?? [],
            ],
        ];
    }

    /**
     * Construye el perfil desde el registro base del Baúl (fallback sin snapshot).
     * Los traits se leen del campo public_facade (JSON) en lugar de la tabla character_traits eliminada.
     */
    private function buildCharacterFromVault(mixed $character, array $runtimeByCharacter): array
    {
        $traitsData = [];
        if (! empty($character->public_facade)) {
            $decoded = json_decode((string) $character->public_facade, true);
            if (is_array($decoded)) {
                $traitsData = $decoded;
            }
        }

        $voiceTrait = collect($traitsData)->firstWhere('key', 'voz');
        $voice = null;
        if (is_array($voiceTrait) && ! empty($voiceTrait['bullets'])) {
            $voice = implode(' | ', array_column($voiceTrait['bullets'], 'body'));
        }

        return [
            'id' => $character->id,
            'name' => $character->name,
            'role' => $character->pivot->role,
            'voice' => $voice,
            'snapshot_version' => null,
            'profile' => [
                'traits' => array_map(static fn (array $trait): array => [
                    'key' => $trait['key'] ?? '',
                    'title' => $trait['title'] ?? '',
                    'bullets' => array_column($trait['bullets'] ?? [], 'body'),
                ], $traitsData),
                'triggers' => [],
                'runtimeStatus' => $runtimeByCharacter[$character->id] ?? [],
            ],
        ];
    }
}
