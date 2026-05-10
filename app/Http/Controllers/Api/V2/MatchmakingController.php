<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Repositories\PlayerMatchmakingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MatchmakingController extends Controller
{
    public function __construct(
        private readonly PlayerMatchmakingRepository $matchmakingRepository,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query'              => ['required', 'string', 'min:3'],
            'filters'                      => ['nullable', 'array'],
            'filters.experience_level'     => ['nullable', 'integer', 'min:1', 'max:5'],
            'filters.verbosity_level'      => ['nullable', 'integer', 'min:1', 'max:5'],
            'filters.red_lines_to_avoid'   => ['nullable', 'array'],
            'filters.red_lines_to_avoid.*' => ['string'],
            'limit'              => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $results = $this->matchmakingRepository->findCompatiblePlayers(
                queryText: $validated['query'],
                filters:   $validated['filters'] ?? [],
                limit:     (int) ($validated['limit'] ?? 10),
            );

            $formatted = array_map(function (array $item): array {
                /** @var \App\Models\Player $player */
                $player = $item['player'];

                return [
                    'player_id'  => $player->id,
                    'username'   => $player->username,
                    'discord_id' => $player->discord_id,
                    'elo'        => $player->elo,
                    'score'      => round((float) $item['score'], 4),
                ];
            }, $results);

            return response()->json($formatted);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
