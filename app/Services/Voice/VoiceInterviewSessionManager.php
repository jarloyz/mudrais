<?php

namespace App\Services\Voice;

use App\Domains\Community\Models\Guild;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use App\Models\AiPromptTemplate;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class VoiceInterviewSessionManager
{
    private const SESSION_TTL_MINUTES  = 60;
    private const QUESTION_TTL_MINUTES = 5;
    private const LOCK_SECONDS         = 30;

    public function __construct(
        private readonly InterviewerAgent $interviewer,
        private readonly ArchetypeMutatorService $mutatorService,
    ) {}

    // ── Session lifecycle ─────────────────────────────────────────────────────

    /**
     * Crea una nueva sesión de voz:
     * - Busca el player por discord_id.
     * - Construye la cola de archetypes incompletos.
     * - Persiste el estado inicial en Redis.
     * - Devuelve {session_id, opening_question}.
     *
     * @throws \RuntimeException si el guild o player no existen.
     */
    public function startSession(
        string $discordId,
        string $discordGuildId,
        string $locale = 'es',
    ): array {
        Log::info('[VoiceInterviewSessionManager@startSession] Inicio', [
            'discord_id'       => $discordId,
            'discord_guild_id' => $discordGuildId,
        ]);

        $player = Player::where('discord_id', $discordId)->first();
        if (! $player) {
            throw new \RuntimeException(__('discord.voice_no_player'));
        }

        $guild = Guild::where('discord_guild_id', $discordGuildId)->first();
        if (! $guild) {
            throw new \RuntimeException(__('discord.voice_guild_not_found'));
        }

        $archetypeQueue = $this->buildArchetypeQueue((string) $player->id, $discordGuildId);

        if (empty($archetypeQueue)) {
            throw new \RuntimeException(__('discord.voice_all_archetypes_complete'));
        }

        $currentArchetypeId = array_shift($archetypeQueue);
        $sessionId          = $this->generateSessionId();
        $username           = $player->username ?? $player->discord_username ?? 'jugador';

        // All field types — voice asks every field verbally (select, range, boolean…)
        $fields = $this->interviewer->resolveFields($currentArchetypeId, 'registration');

        $state = [
            'session_id'           => $sessionId,
            'discord_id'           => $discordId,
            'discord_guild_id'     => $discordGuildId,
            'player_id'            => (string) $player->id,
            'username'             => $username,
            'locale'               => $locale,
            'archetype_queue'      => $archetypeQueue,
            'current_archetype_id' => $currentArchetypeId,
            'turn'                 => 0,
            'extracted_fields'     => [],
            'conversation_history' => [],
            'missing_required_keys'=> array_column(array_filter($fields, fn($f) => $f['is_required']), 'field_key'),
            'missing_optional_keys'=> array_column(array_filter($fields, fn($f) => ! $f['is_required']), 'field_key'),
            'status'               => 'active',
            'started_at'           => time(),
        ];

        $this->persistSession($sessionId, $state);

        $openingQuestion = $this->resolveOpeningQuestion($currentArchetypeId, $username, $locale);

        Log::info('[VoiceInterviewSessionManager@startSession] Sesión creada', [
            'session_id'            => $sessionId,
            'current_archetype_id'  => $currentArchetypeId,
            'queue_length'          => count($archetypeQueue),
        ]);

        return [
            'session_id'          => $sessionId,
            'opening_question'    => $openingQuestion,
            'current_archetype_id'=> $currentArchetypeId,
        ];
    }

    // ── Multi-archetype queue ─────────────────────────────────────────────────

    /**
     * Retorna los IDs de archetypes disponibles en el guild que NO tienen
     * todos los campos requeridos completos para el player dado.
     * Primario primero (is_primary = true en el pivot).
     *
     * @return string[]
     */
    public function buildArchetypeQueue(string $playerId, string $discordGuildId): array
    {
        $guild = Guild::with(['archetypes', 'vaults.archetypes'])
            ->where('discord_guild_id', $discordGuildId)
            ->first();

        if (! $guild) {
            return [];
        }

        // Fuente primaria: relación directa guild_archetypes (cuando existe).
        // Fuente secundaria: archetypes derivados de los vaults de la guild
        // (pivot archetype_vault con is_primary). Cubre el caso más común donde
        // los archetypes se asocian a la guild a través de sus vaults.
        $directArchetypes = $guild->archetypes;

        $vaultArchetypes = $guild->vaults
            ->flatMap(fn($vault) => $vault->archetypes->map(fn($archetype) => [
                'id'         => (string) $archetype->id,
                'archetype'  => $archetype,
                'is_primary' => (bool) ($archetype->pivot->is_primary ?? false),
            ]))
            ->unique('id')
            ->sortByDesc('is_primary')
            ->pluck('archetype');

        // Unir ambas fuentes, deduplicar, primarios primero.
        $allArchetypes = $directArchetypes->isEmpty()
            ? $vaultArchetypes
            : $directArchetypes->merge($vaultArchetypes)->unique('id');

        return $allArchetypes
            ->filter(fn($archetype) => ! $this->isArchetypeComplete($playerId, (string) $archetype->id))
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->values()
            ->toArray();
    }

    /**
     * Devuelve true si el PlayerArchetypeProfile del player tiene todos los
     * campos requeridos del archetype completados.
     */
    public function isArchetypeComplete(string $playerId, string $archetypeId): bool
    {
        $profile = PlayerArchetypeProfile::where('player_id', $playerId)
            ->where('archetype_id', $archetypeId)
            ->first();

        if (! $profile) {
            return false;
        }

        $mutators = $this->mutatorService->getFieldsForContext($archetypeId, 'registration');

        if ($mutators->isEmpty()) {
            // Sin mutadores: verificar campos defaults
            $raw = $profile->content_raw ?? [];
            return trim($raw['preferences'] ?? '') !== '' && trim($raw['style'] ?? '') !== '';
        }

        $metadata = $profile->metadata ?? [];
        $raw      = $profile->content_raw ?? [];

        foreach ($mutators->where('is_required', true) as $mutator) {
            $value = $metadata[$mutator->field_key] ?? $raw[$mutator->field_key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Mueve el estado al siguiente archetype de la cola.
     * Devuelve false cuando la cola está vacía (sesión terminada).
     */
    public function advanceToNextArchetype(string $sessionId): bool
    {
        $state = $this->getSession($sessionId);
        if ($state === null) {
            return false;
        }

        $queue = $state['archetype_queue'] ?? [];

        if (empty($queue)) {
            Log::info('[VoiceInterviewSessionManager] Cola de archetypes agotada', [
                'session_id' => $sessionId,
            ]);
            $state['status'] = 'completed';
            $this->persistSession($sessionId, $state);
            return false;
        }

        $nextArchetypeId = array_shift($queue);

        // All field types — voice asks every field verbally (select, range, boolean…)
        $fields = $this->interviewer->resolveFields($nextArchetypeId, 'registration');

        $state = array_merge($state, [
            'archetype_queue'       => $queue,
            'current_archetype_id'  => $nextArchetypeId,
            'turn'                  => 0,
            'extracted_fields'      => [],
            'conversation_history'  => [],
            'missing_required_keys' => array_column(array_filter($fields, fn($f) => $f['is_required']), 'field_key'),
            'missing_optional_keys' => array_column(array_filter($fields, fn($f) => ! $f['is_required']), 'field_key'),
        ]);

        $this->persistSession($sessionId, $state);

        Log::info('[VoiceInterviewSessionManager] Avanzando al siguiente archetype', [
            'session_id'      => $sessionId,
            'next_archetype'  => $nextArchetypeId,
            'remaining_queue' => count($queue),
        ]);

        return true;
    }

    /**
     * Finaliza el archetype actual:
     * - Establece la caché que ProcessRegistroStep2Job espera leer.
     * - Despacha el job con token 'SCRIPT_VOICE' para suprimir Discord API calls.
     */
    public function completeCurrentArchetype(
        string $sessionId,
        array $extractedFields,
        string $discordId,
        string $username,
    ): void {
        $state       = $this->getSession($sessionId);
        $archetypeId = $state['current_archetype_id'] ?? null;
        $guildId     = $state['discord_guild_id']      ?? null;
        $player      = Player::where('discord_id', $discordId)->first();

        Log::info('[VoiceInterviewSessionManager@completeCurrentArchetype] Completando archetype', [
            'session_id'   => $sessionId,
            'archetype_id' => $archetypeId,
            'discord_id'   => $discordId,
        ]);

        // Preparar caché que ProcessRegistroStep2Job espera leer en su handle()
        Cache::put("registro_step1_{$discordId}", [
            'is_edit'     => false,
            'nationality' => $player?->nationality,
        ], now()->addMinutes(30));

        if ($archetypeId) {
            Cache::put("registro_archetype_{$discordId}", $archetypeId, now()->addMinutes(30));
        }

        ProcessRegistroStep2Job::dispatch(
            $discordId,
            $extractedFields,
            'SCRIPT_VOICE',
            $guildId,
            $username,
        );
    }

    /**
     * Resuelve la pregunta de apertura para un archetype dado.
     * Prioridad: ArchetypePrompt('interview_opening') → AiPromptTemplate → i18n fallback.
     */
    public function resolveOpeningQuestion(string $archetypeId, string $username, string $locale): string
    {
        $archetype       = Archetype::find($archetypeId);
        $openingQuestion = $archetype?->getPromptFor('interview_opening');

        if (! $openingQuestion) {
            $globalOpening   = AiPromptTemplate::getBody('interview_opening', '');
            $openingQuestion = $globalOpening !== ''
                ? str_replace('{username}', $username, $globalOpening)
                : __('discord.interview_opening_question', ['username' => $username]);
        }

        return $openingQuestion;
    }

    // ── Redis helpers ─────────────────────────────────────────────────────────

    public function getSession(string $sessionId): ?array
    {
        $data = Cache::get($this->sessionKey($sessionId));

        if ($data === null) {
            Log::debug('[VoiceInterviewSessionManager] Sesión no encontrada', ['session_id' => $sessionId]);
            return null;
        }

        return $data;
    }

    public function updateSession(string $sessionId, array $state): void
    {
        Log::debug('[VoiceInterviewSessionManager] Actualizando sesión', [
            'session_id' => $sessionId,
            'turn'       => $state['turn'] ?? null,
            'status'     => $state['status'] ?? null,
        ]);

        $this->persistSession($sessionId, $state);
    }

    public function pushNextQuestion(string $sessionId, string $question): void
    {
        Log::debug('[VoiceInterviewSessionManager] Publicando siguiente pregunta en Redis list', [
            'session_id' => $sessionId,
            'question'   => mb_substr($question, 0, 80),
        ]);

        $key = $this->questionKey($sessionId);

        // LPUSH en lista sin prefix → voice-bridge lo consume con BLPOP sin intermediarios HTTP.
        Redis::connection('voice')->lpush($key, $question);
        Redis::connection('voice')->expire($key, self::QUESTION_TTL_MINUTES * 60);
    }

    /**
     * Lee y elimina atómicamente la siguiente pregunta (LPOP no bloqueante).
     * Usado por el endpoint HTTP de polling para pruebas / fallback.
     * El voice-bridge consume directamente via BLPOP en Redis.
     */
    public function popNextQuestion(string $sessionId): ?string
    {
        $question = Redis::connection('voice')->lpop($this->questionKey($sessionId));

        if ($question === null) {
            return null;
        }

        Log::debug('[VoiceInterviewSessionManager] Pregunta consumida (LPOP)', [
            'session_id' => $sessionId,
            'question'   => mb_substr($question, 0, 80),
        ]);

        return $question;
    }

    public function acquireProcessingLock(string $sessionId): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::lock($this->lockKey($sessionId), self::LOCK_SECONDS);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function persistSession(string $sessionId, array $state): void
    {
        Cache::put(
            $this->sessionKey($sessionId),
            $state,
            now()->addMinutes(self::SESSION_TTL_MINUTES),
        );
    }

    // ── Voice-bridge start commands (Discord → Redis → Node.js) ──────────────

    /**
     * Encola una señal para que el voice-bridge inicie una sesión.
     * Usa una lista Redis (RPUSH) para que múltiples señales no se pisen.
     */
    public function pushStartCommand(
        string $discordId,
        string $guildId,
        string $locale = 'es',
        string $interactionToken = '',
        string $appId = '',
    ): void {
        $payload = json_encode([
            'discord_id'        => $discordId,
            'guild_id'          => $guildId,
            'locale'            => $locale,
            'interaction_token' => $interactionToken,
            'app_id'            => $appId,
            'created_at'        => time(),
        ]);

        Redis::connection('voice')->rpush('voice_bridge_pending_starts', $payload);

        Log::debug('[VoiceInterviewSessionManager@pushStartCommand] Señal encolada', [
            'discord_id' => $discordId,
            'guild_id'   => $guildId,
        ]);
    }

    /**
     * Consume la siguiente señal pendiente (LPOP).
     * Devuelve null si no hay ninguna.
     *
     * @return array{discord_id: string, guild_id: string, locale: string}|null
     */
    public function popStartCommand(): ?array
    {
        $raw = Redis::connection('voice')->lpop('voice_bridge_pending_starts');

        // LPOP devuelve null o false cuando la lista está vacía según el driver.
        if ($raw === null || $raw === false) {
            return null;
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data) || !isset($data['discord_id'], $data['guild_id'])) {
            Log::warning('[VoiceInterviewSessionManager@popStartCommand] Payload inválido descartado', [
                'raw' => $raw,
            ]);
            return null;
        }

        return $data;
    }

    private function sessionKey(string $sessionId): string
    {
        return "voice_session:{$sessionId}";
    }

    private function questionKey(string $sessionId): string
    {
        return "voice_next_question:{$sessionId}";
    }

    private function lockKey(string $sessionId): string
    {
        return "voice_processing_lock:{$sessionId}";
    }

    protected function generateSessionId(): string
    {
        return (string) Str::uuid7();
    }
}
