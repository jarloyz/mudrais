<?php

namespace App\Domains\Community\Actions;

use App\Domains\Community\DTOs\RegisterPlayerDTO;
use App\Domains\Community\Events\PlayerRegisteredEvent;
use App\Domains\Community\Models\Player;
use App\Infrastructure\Ai\Agents\ContentSafetyAgent;
use App\Infrastructure\Ai\Agents\ProfileTranslatorAgent;
use App\Jobs\NormalizePlayerTagsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegisterPlayerAction
{
    public function __construct(
        private ProfileTranslatorAgent $translator,
        private ContentSafetyAgent $safetyAgent,
    ) {
    }

    public function execute(RegisterPlayerDTO $dto, array $rawData = []): Player
    {
        Log::debug('[RegisterPlayerAction@execute] Inicio', [
            'discord_id'       => $dto->discordId,
            'discord_guild_id' => $dto->discordGuildId,
        ]);

        $aboutMe = $rawData['about_me'] ?? null;
        if ($aboutMe !== null && ! $this->safetyAgent->check($aboutMe)) {
            Log::warning('[RegisterPlayerAction@execute] about_me rechazado por safety check.', [
                'discord_id' => $dto->discordId,
            ]);
            $aboutMe = null;
        }

        $rawData = $this->translateRawFields($rawData);

        $player = DB::transaction(function () use ($dto, $rawData, $aboutMe): Player {
            return Player::updateOrCreate(
                ['discord_id' => $dto->discordId],
                array_filter([
                    'username'             => $dto->username,
                    'age'                  => $rawData['age'] ?? null,
                    'country_code'         => $rawData['country_code'] ?? null,
                    'nationality'          => $rawData['nationality'] ?? null,
                    'experience_level'     => $rawData['experience_level'] ?? null,
                    'verbosity_level'      => $rawData['verbosity_level'] ?? null,
                    'schedule_raw'         => $rawData['schedule_raw'] ?? null,
                    'schedule'             => $rawData['schedule'] ?? null,
                    'narrative_style_text' => $rawData['narrative_style'] ?? null,
                    'about_me'             => $aboutMe,
                ], fn ($v) => $v !== null),
            );
        });

        PlayerRegisteredEvent::dispatch($player, $dto->discordGuildId);

        Log::info('[RegisterPlayerAction@execute] Jugador registrado.', [
            'player_id'  => $player->id,
            'discord_id' => $player->discord_id,
        ]);

        // Path legacy sin contexto de arquetipo.
        // IndexPlayerStyleJob requiere un player_archetype_profile_id (post-V2);
        // se despacha en ProcessRegistroStep2Job y GatekeeperProfileService.

        return $player;
    }

    private function translateRawFields(array $data): array
    {
        $toTranslate = array_filter([
            'red_lines'    => $this->splitRaw($data['raw_red_lines']    ?? ''),
            'yellow_lines' => $this->splitRaw($data['raw_yellow_lines'] ?? ''),
            'affinities'   => $this->splitRaw($data['raw_preferences']  ?? ''),
        ], fn ($v) => $v !== [] && $v !== '');

        if (empty($toTranslate)) {
            return $data;
        }

        $translated = $this->translator->translate($toTranslate);

        if (! empty($translated['red_lines'])) {
            $data['raw_red_lines'] = implode(', ', $translated['red_lines']);
        }
        if (! empty($translated['yellow_lines'])) {
            $data['raw_yellow_lines'] = implode(', ', $translated['yellow_lines']);
        }
        if (! empty($translated['affinities'])) {
            $data['raw_preferences'] = implode(', ', $translated['affinities']);
        }

        return $data;
    }

    /** @return list<string> */
    private function splitRaw(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*[,;]\s*|\s+y\s+/iu', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
