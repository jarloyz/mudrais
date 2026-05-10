<?php

namespace App\Application\Services;

use App\Infrastructure\Ai\Agents\GatekeeperProfileAgent;
use App\Jobs\IndexPlayerStyleJob;
use App\Jobs\NormalizePlayerTagsJob;
use App\Domains\Matchmaking\Models\Archetype;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class GatekeeperProfileService
{
    public function __construct(
        private GatekeeperProfileAgent $agent,
        private TagNormalizerService $tagNormalizer,
    ) {}

    public function processPlayerProfile(Player $player, string $profileText): ?PlayerArchetypeProfile
    {
        Log::info('[GatekeeperProfileService@processPlayerProfile] Iniciando', [
            'player_id'  => $player->id,
            'discord_id' => $player->discord_id,
        ]);

        $data = $this->agent->process($profileText, $player->id);

        if (! $data) {
            Log::warning('[GatekeeperProfileService@processPlayerProfile] Agent devolvió null', [
                'player_id' => $player->id,
            ]);
            return null;
        }

        // Escribir solo campos globales en players
        $player->update(array_filter([
            'age'         => $data['age'] ?? null,
            'nationality' => $data['nationality'] ?? null,
        ], fn ($v) => $v !== null));

        Log::info('[GatekeeperProfileService@processPlayerProfile] Campos globales guardados en player', [
            'player_id' => $player->id,
            'age'       => $data['age'] ?? null,
        ]);

        // Resolver arquetipo default (no hay contexto de guild en este flujo)
        $archetype = Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto'],
        );

        $experienceLevel = $this->mapExperience($data['experience_level'] ?? null);
        $verbosityLevel  = $this->mapVerbosity($data['verbosity'] ?? null);

        // Escribir campos contextuales en PlayerArchetypeProfile
        $profile = PlayerArchetypeProfile::updateOrCreate(
            ['player_id' => $player->id, 'archetype_id' => $archetype->id],
            [
                'discord_user_id'    => $player->discord_id,
                'positive_prefs'     => $data['affinities'] ?? [],
                'red_lines'          => $data['red_lines'] ?? [],
                'raw_profile'        => $data['raw_profile'] ?? null,
                'preference_profile' => $data['raw_profile'] ?? null,
                'schedule'           => $data['schedule'] ?? null,
                'metadata'           => [
                    'experience_level' => $experienceLevel,
                    'verbosity_level'  => $verbosityLevel,
                    'nationality'      => $data['nationality'] ?? null,
                ],
                'is_vectorized' => false,
            ]
        );

        Log::info('[GatekeeperProfileService@processPlayerProfile] PlayerArchetypeProfile actualizado', [
            'player_id'    => $player->id,
            'profile_id'   => $profile->id,
            'archetype_id' => $archetype->id,
        ]);

        Bus::chain([
            new NormalizePlayerTagsJob(
                $profile,
                $data['red_lines']    ?? [],
                $data['yellow_lines'] ?? [],
                $data['affinities']   ?? [],
            ),
            new IndexPlayerStyleJob($profile->id),
        ])->dispatch();

        return $profile;
    }

    private function mapExperience(?string $level): ?int
    {
        return match ($level) {
            'Novice', 'Novato'           => 1,
            'Veteran', 'Veterano'        => 3,
            'Master', 'Máster', 'Máster' => 5,
            default                      => null,
        };
    }

    private function mapVerbosity(?string $verbosity): ?int
    {
        if ($verbosity === null) {
            return null;
        }

        $v = mb_strtolower($verbosity);

        if (str_contains($v, 'low') || str_contains($v, 'baja') || str_contains($v, 'accion') || str_contains($v, 'acción')) {
            return 1;
        }
        if (str_contains($v, 'high') || str_contains($v, 'alta') || str_contains($v, 'biblia')) {
            return 5;
        }
        if (str_contains($v, 'medium') || str_contains($v, 'media') || str_contains($v, 'mixto')) {
            return 3;
        }

        return null;
    }
}
