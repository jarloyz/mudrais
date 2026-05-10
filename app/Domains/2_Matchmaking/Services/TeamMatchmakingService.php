<?php

namespace App\Domains\Matchmaking\Services;

use App\Domains\Matchmaking\Contracts\HubMatchmakingRepositoryInterface;
use App\Domains\Matchmaking\DTOs\HubMatchResultDTO;
use App\Domains\Matchmaking\DTOs\SlotMatchResultDTO;
use App\Domains\Matchmaking\DTOs\TeamMatchResultDTO;
use App\Domains\Narrative\Models\Activity;
use Illuminate\Support\Facades\Log;

class TeamMatchmakingService
{
    public function __construct(
        private readonly HubMatchmakingRepositoryInterface $repository,
    ) {}

    /**
     * Ejecuta búsqueda multi-slot para una actividad padre.
     * Cada slot hijo usa su ctx1 como criterio de búsqueda (avatar_context_vector).
     * Deduplica candidatos para que un mismo perfil no aparezca en dos slots.
     */
    public function searchForTeam(Activity $parent, string $guildId, bool $filterAvailable = false): TeamMatchResultDTO
    {
        Log::debug('[TeamMatchmakingService@searchForTeam] Inicio', [
            'parent_id'        => $parent->id,
            'required_slots'   => $parent->required_slots,
            'child_count'      => $parent->childActivities->count(),
            'filter_available' => $filterAvailable,
        ]);

        $rawResults     = $this->repository->findProfilesForTeamActivity($parent, $guildId, $filterAvailable);
        $slots          = [];
        $seenQdrantIds  = [];

        foreach ($rawResults as $slotId => $slotData) {
            // Deduplicar: un candidato no puede ocupar dos slots simultáneamente.
            $filtered = collect($slotData['candidates'])
                ->filter(function (HubMatchResultDTO $candidate) use (&$seenQdrantIds) {
                    if (in_array($candidate->qdrantId, $seenQdrantIds, strict: true)) {
                        return false;
                    }
                    $seenQdrantIds[] = $candidate->qdrantId;
                    return true;
                })
                ->values()
                ->all();

            $slots[] = new SlotMatchResultDTO(
                slotActivityId: $slotId,
                slotTitle: $slotData['slot_title'],
                candidates: $filtered,
            );
        }

        Log::info('[TeamMatchmakingService@searchForTeam] Búsqueda completada', [
            'parent_id'    => $parent->id,
            'slots_found'  => count($slots),
            'filled_slots' => collect($slots)->filter(fn(SlotMatchResultDTO $s) => $s->hasCandidates())->count(),
        ]);

        return new TeamMatchResultDTO($parent, $slots);
    }

    /**
     * Búsqueda inbound simple (actividad individual, no equipo).
     * Usa ctx1 de la actividad para encontrar profiles compatibles.
     *
     * @return HubMatchResultDTO[]
     */
    public function searchForActivity(Activity $activity, string $guildId): array
    {
        Log::debug('[TeamMatchmakingService@searchForActivity] Inicio', [
            'activity_id' => $activity->id,
            'guild_id'    => $guildId,
        ]);

        $filterAvailable = (bool) ($activity->content_raw['filter_available_only'] ?? false);

        $results = $this->repository->findProfilesForActivity($activity, $guildId, $filterAvailable);

        Log::info('[TeamMatchmakingService@searchForActivity] Resultados obtenidos', [
            'activity_id' => $activity->id,
            'count'       => count($results),
            'filter_available' => $filterAvailable,
        ]);

        return $results;
    }
}
