<?php

namespace App\Application\Services;

use App\Infrastructure\Ai\Agents\ContentSafetyAgent;
use App\Infrastructure\Ai\Agents\ProfileTranslatorAgent;
use App\Jobs\NormalizePlayerTagsJob;
use App\Models\Player;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlayerRegistrationService
{
    public function __construct(
        private ProfileTranslatorAgent $translator,
        private ContentSafetyAgent $safetyAgent,
    ) {}

    public function register(array $data): Player
    {
        $aboutMe = $data['about_me'] ?? null;
        if ($aboutMe !== null && ! $this->safetyAgent->check($aboutMe)) {
            Log::warning('PlayerRegistrationService: about_me rejected by safety check.', [
                'discord_id' => $data['discord_id'],
            ]);
            $aboutMe = null;
        }

        // Phase 2: translate tag fields to English before any processing
        $data = $this->translateRawFields($data);

        // Phase 3: relational persistence — synchronous, inside a transaction
        $player = DB::transaction(function () use ($data, $aboutMe): Player {
            return Player::updateOrCreate(
                ['discord_id' => $data['discord_id']],
                array_filter([
                    'username'             => $data['username'],
                    'age'                  => $data['age'] ?? null,
                    'country_code'         => $data['country_code'] ?? null,
                    'nationality'          => $data['nationality'] ?? null,
                    'experience_level'     => $data['experience_level'] ?? null,
                    'verbosity_level'      => $data['verbosity_level'] ?? null,
                    'schedule_raw'         => $data['schedule_raw'] ?? null,
                    'schedule'             => $data['schedule'] ?? null,
                    'narrative_style_text' => $data['narrative_style'] ?? null,
                    'about_me'             => $aboutMe,
                ], fn ($v) => $v !== null),
            );
        });

        Log::info('PlayerRegistrationService: player registered.', [
            'player_id'  => $player->id,
            'discord_id' => $player->discord_id,
        ]);

        // Chain: normalize tags first, then index style vector (tags must exist before indexing)
        $chain = [
            new NormalizePlayerTagsJob(
                $player,
                $this->splitRaw($data['raw_red_lines']   ?? ''),
                $this->splitRaw($data['raw_yellow_lines'] ?? ''),
                $this->splitRaw($data['raw_preferences']  ?? ''),
            ),
        ];

        // IndexPlayerStyleJob requiere player_archetype_profile_id (post-V2);
        // se despacha en ProcessRegistroStep2Job y GatekeeperProfileService.

        Bus::chain($chain)->dispatch();

        return $player;
    }

    /**
     * Translate raw text fields to English via ProfileTranslatorAgent.
     * Operates on the raw comma-separated strings before splitting.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function translateRawFields(array $data): array
    {
        $toTranslate = array_filter([
            'red_lines'    => $this->splitRaw($data['raw_red_lines']   ?? ''),
            'yellow_lines' => $this->splitRaw($data['raw_yellow_lines'] ?? ''),
            'affinities'   => $this->splitRaw($data['raw_preferences']  ?? ''),
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        if (empty($toTranslate)) {
            return $data;
        }

        $translated = $this->translator->translate($toTranslate);

        // Write translated items back as comma-joined strings (splitRaw will re-split them later)
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

    /**
     * @return list<string>
     */
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
