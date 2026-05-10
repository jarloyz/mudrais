<?php

namespace App\Domains\Matchmaking\Delivery;

use App\Domains\Matchmaking\Actions\ResolveGuildArchetypeAction;
use App\Domains\Matchmaking\Contracts\MatchmakingRepositoryInterface;
use App\Domains\Matchmaking\Events\PlayersMatchedEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MatchmakingController extends Controller
{
    

    public function __construct(
        private readonly MatchmakingRepositoryInterface $matchmakingRepository,
        private readonly ResolveGuildArchetypeAction $archetypeResolver,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        Log::debug('[MatchmakingController@search] Inicio', [
            'discord_user_id' => $request->user()?->id,
        ]);

        $validated = $request->validate([
            'discord_user_id'  => ['required', 'string'],
            'discord_guild_id' => ['required', 'string'],
            'limit'            => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $archetype = $this->archetypeResolver->execute($validated['discord_guild_id']);

            $results = $this->matchmakingRepository->findCompatiblePlayers(
                discordUserId:       $validated['discord_user_id'],
                archetypeVectorName: $archetype->qdrant_vector_name,
                discordGuildId:      $validated['discord_guild_id'],
                limit:               (int) ($validated['limit'] ?? 5),
            );

            if (! empty($results)) {
                PlayersMatchedEvent::dispatch(
                    array_column($results, 'discordUserId'),
                    $archetype->qdrant_vector_name,
                    $validated['discord_guild_id'],
                );
            }

            Log::info('[MatchmakingController@search] Búsqueda completada.', [
                'results_count' => count($results),
                'archetype'     => $archetype->qdrant_vector_name,
            ]);

            return response()->json(array_map(
                fn ($r) => [
                    'discord_user_id' => $r->discordUserId,
                    'score'           => round($r->score, 4),
                    'metadata'        => $r->metadata,
                ],
                $results,
            ));
        } catch (Throwable $e) {
            Log::error('[MatchmakingController@search] Error en búsqueda.', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
