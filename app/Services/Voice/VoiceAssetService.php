<?php

namespace App\Services\Voice;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\Voice\VoiceTextTranslator;

/**
 * Genera y almacena los audios WAV pre-sintetizados para las sesiones de voz:
 *   - opening.wav   → pregunta de apertura del archetype
 *   - filler_0..3.wav → frases de relleno contextuales generadas por LLM
 *
 * Los archivos se guardan en storage/app/voice-assets/{archetypeId}/.
 */
class VoiceAssetService
{
    private const DISK         = 'local';
    private const PREFIX       = 'voice-assets';
    private const FILLER_COUNT = 4;

    /** ID especial para los assets estáticos del sistema (no vinculados a un archetype). */
    public const STATIC_ID = 'static';

    /**
     * Textos fijos del sistema, siempre en inglés para Speechmatics.
     * IMPORTANTE: mantener sincronizado con VOICE_PROMPTS.en en VoiceSession.mjs.
     */
    public const STATIC_PROMPTS = [
        'greeting.wav'          => "Hi! I'm Mudrais, and I'm here to help you find your best match. Let's start with a few questions.",
        'still_there.wav'       => 'Are you still there?',
        'goodbye.wav'           => 'It was a pleasure talking with you. Goodbye.',
        'generic_filler_0.wav'  => 'Interesting, give me a moment.',
        'generic_filler_1.wav'  => 'Let me process your answer.',
        'generic_filler_2.wav'  => 'Got it, one moment.',
        'generic_filler_3.wav'  => 'Understood, processing.',
    ];

    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $resolver,
        private readonly VoiceTextTranslator $translator,
    ) {}

    // ── Generación ────────────────────────────────────────────────────────────

    /**
     * Genera y almacena los WAVs estáticos del sistema (saludo, silencio, despedida, fillers genéricos).
     * Se ejecuta una sola vez (o cuando cambien los textos).
     *
     * @return array{generated: int, total: int}
     */
    public function generateStatic(): array
    {
        Log::info('[VoiceAssetService@generateStatic] Inicio');

        $speechmaticsKey = config('services.speechmatics.key', '');
        if (! $speechmaticsKey) {
            Log::error('[VoiceAssetService@generateStatic] SPEECHMATICS_API_KEY no configurada — agrega SPEECHMATICS_API_KEY al .env de Laravel.');
            return ['generated' => 0, 'total' => count(self::STATIC_PROMPTS)];
        }

        $generated = 0;
        foreach (self::STATIC_PROMPTS as $filename => $text) {
            if ($this->synthesizeAndStore($text, self::STATIC_ID, $filename)) {
                $generated++;
            }
        }

        Log::info('[VoiceAssetService@generateStatic] Completado', [
            'generated' => $generated,
            'total'     => count(self::STATIC_PROMPTS),
        ]);

        return ['generated' => $generated, 'total' => count(self::STATIC_PROMPTS)];
    }

    /**
     * Genera y almacena todos los audios para un archetype.
     * Llama al LLM para generar frases de relleno y a la TTS de Speechmatics para sintetizarlas.
     *
     * @return array{opening: bool, fillers: int}
     */
    public function generateAll(string $archetypeId): array
    {
        Log::info('[VoiceAssetService@generateAll] Inicio', ['archetype_id' => $archetypeId]);

        // Cargar relación prompts explícitamente para evitar lazy loading inconsistente.
        $archetype = Archetype::with('prompts')->find($archetypeId);
        if (! $archetype) {
            Log::warning('[VoiceAssetService@generateAll] Archetype no encontrado', ['archetype_id' => $archetypeId]);
            return ['opening' => false, 'fillers' => 0];
        }

        $openingText = $archetype->getPromptFor('interview_opening');
        if (! $openingText) {
            Log::warning('[VoiceAssetService@generateAll] Sin interview_opening guardado — genera primero la pregunta de apertura en Filament → Archetype → Prompts', [
                'archetype_id'    => $archetypeId,
                'prompts_loaded'  => $archetype->prompts->count(),
                'prompt_types'    => $archetype->prompts->pluck('agent_type')->toArray(),
            ]);
            return ['opening' => false, 'fillers' => 0];
        }

        // Usar versión en inglés pre-generada si existe; si no, traducir en tiempo real.
        // interview_opening_en se guarda por GenerateInterviewOpeningJob en paralelo.
        $openingTextEn = $archetype->getPromptFor('interview_opening_en');

        if ($openingTextEn) {
            Log::info('[VoiceAssetService@generateAll] Usando interview_opening_en pre-generado', [
                'archetype_id' => $archetypeId,
                'preview'      => mb_substr($openingTextEn, 0, 60),
            ]);
        } else {
            Log::info('[VoiceAssetService@generateAll] interview_opening_en no encontrado — traduciendo via LLM', [
                'archetype_id' => $archetypeId,
            ]);
            $openingTextEn = $this->translator->toEnglish($openingText);

            Log::info('[VoiceAssetService@generateAll] Texto traducido para TTS', [
                'archetype_id' => $archetypeId,
                'original'     => mb_substr($openingText, 0, 60),
                'translated'   => mb_substr($openingTextEn, 0, 60),
            ]);
        }

        // Validar que la API key de Speechmatics está configurada en el .env de Laravel
        // (independiente de la del voice-bridge — son entornos distintos).
        $speechmaticsKey = config('services.speechmatics.key', '');
        if (! $speechmaticsKey) {
            Log::error('[VoiceAssetService@generateAll] SPEECHMATICS_API_KEY no configurada en el .env de Laravel — no se pueden generar audios WAV.', [
                'archetype_id' => $archetypeId,
                'fix'          => 'Agrega SPEECHMATICS_API_KEY=<tu_key> al archivo .env del servidor Laravel (no al del voice-bridge)',
            ]);
            return ['opening' => false, 'fillers' => 0];
        }

        Log::info('[VoiceAssetService@generateAll] API key presente — iniciando síntesis TTS', [
            'archetype_id' => $archetypeId,
            'voice'        => config('services.speechmatics.voice', 'sarah'),
            'text_en'      => mb_substr($openingTextEn, 0, 80),
        ]);

        // Sintetizar pregunta de apertura en inglés.
        $openingOk = $this->synthesizeAndStore($openingTextEn, $archetypeId, 'opening.wav');

        // Generar frases de relleno contextuales via LLM y sintetizarlas.
        $fillerTexts = $this->generateFillerTexts($archetype, $openingText);

        $fillerOk = 0;
        foreach ($fillerTexts as $i => $text) {
            if ($this->synthesizeAndStore($text, $archetypeId, "filler_{$i}.wav")) {
                $fillerOk++;
            }
        }

        Log::info('[VoiceAssetService@generateAll] Completado', [
            'archetype_id' => $archetypeId,
            'opening_ok'   => $openingOk,
            'fillers_ok'   => $fillerOk,
            'fillers_total' => count($fillerTexts),
        ]);

        return ['opening' => $openingOk, 'fillers' => $fillerOk];
    }

    // ── Consulta ──────────────────────────────────────────────────────────────

    /**
     * Devuelve la ruta absoluta del archivo de audio si existe, null si no.
     */
    public function assetPath(string $archetypeId, string $filename): ?string
    {
        $path = self::PREFIX . "/{$archetypeId}/{$filename}";

        return Storage::disk(self::DISK)->exists($path)
            ? Storage::disk(self::DISK)->path($path)
            : null;
    }

    /**
     * Lista los filenames de audio pre-generados disponibles para un archetype.
     * @return string[]
     */
    public function listAssets(string $archetypeId): array
    {
        $dir = self::PREFIX . "/{$archetypeId}";

        if (! Storage::disk(self::DISK)->directoryExists($dir)) {
            return [];
        }

        return array_map(
            fn ($p) => basename($p),
            Storage::disk(self::DISK)->files($dir),
        );
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Genera frases de relleno contextuales usando el LLM.
     * Las frases son breves, naturales, sin preguntas, y relacionadas con el tema del archetype.
     *
     * @return string[]
     */
    private function generateFillerTexts(Archetype $archetype, string $openingQuestion): array
    {
        $archetypeName = $archetype->name ?? 'this profile';

        $aiFieldLabels = $archetype->mutators()
            ->where('context', 'registration')
            ->whereIn('field_type', InterviewerAgent::AI_FIELD_TYPES)
            ->orderBy('sort_order')
            ->pluck('field_label')
            ->implode(', ');

        $systemPrompt = <<<PROMPT
You are preparing {$this->fillerCount()} short audio filler phrases IN ENGLISH for a voice interview about "{$archetypeName}".
The interview covers: {$aiFieldLabels}.
The opening question is: "{$openingQuestion}"

Generate exactly {$this->fillerCount()} short transition phrases IN ENGLISH (8–14 words each) that a warm, professional interviewer says while reviewing an answer before moving on. Do NOT ask new questions. Do NOT repeat the opening question. Keep them natural and conversational.

IMPORTANT: All phrases must be in English — they will be sent directly to a text-to-speech engine.

Return ONLY the {$this->fillerCount()} phrases, one per line, no quotes, no numbering, no explanations.
PROMPT;

        $model = $this->resolver->resolveExplicitAgentModel(null, 'talkator');

        if (! $model) {
            Log::warning('[VoiceAssetService@generateFillerTexts] Sin modelo configurado para el agente talkator — configura el modelo en Filament → Settings → Agentes → Talkator', [
                'archetype' => $archetypeName,
            ]);
            return [];
        }

        $provider     = $this->resolver->resolveAgentProvider(null, 'talkator');
        $reasoning    = $this->resolver->resolveAgentReasoning(null, 'talkator');
        $budgetTokens = $this->resolver->resolveAgentBudgetTokens(null, 'talkator');

        $options = $provider ? ['_provider' => $provider] : [];
        if ($reasoning) {
            $options['reasoning'] = ['enabled' => true, 'budget_tokens' => $budgetTokens];
        }

        Log::debug('[VoiceAssetService@generateFillerTexts] Llamando LLM', [
            'archetype' => $archetypeName,
            'model'     => $model,
        ]);

        try {
            $response = $this->gateway->chat(
                $model,
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => 'Generate the filler phrases now.'],
                ],
                0.75,
                200,
                null,
                null,
                null,
                $options,
            );

            $lines = array_filter(
                array_map('trim', explode("\n", $response['text'] ?? '')),
                fn ($l) => mb_strlen($l) >= 5,
            );

            $result = array_slice(array_values($lines), 0, self::FILLER_COUNT);

            Log::info('[VoiceAssetService@generateFillerTexts] Frases generadas', [
                'count'  => count($result),
                'sample' => $result[0] ?? '',
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('[VoiceAssetService@generateFillerTexts] LLM falló', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Llama a Speechmatics TTS preview y guarda el WAV resultante en disco.
     */
    private function synthesizeAndStore(string $text, string $archetypeId, string $filename): bool
    {
        $voice = config('services.speechmatics.voice', 'sarah');
        $key   = config('services.speechmatics.key', '');
        $url   = "https://preview.tts.speechmatics.com/generate/{$voice}?output_format=wav_16000";

        Log::info('[VoiceAssetService@synthesizeAndStore] TTS request → Speechmatics', [
            'archetype_id' => $archetypeId,
            'filename'     => $filename,
            'text_preview' => mb_substr($text, 0, 60),
            'voice'        => $voice,
            'url'          => $url,
        ]);

        $t0 = microtime(true);

        try {
            $response = Http::withToken($key)
                ->withBody(json_encode(['text' => $text]), 'application/json')
                ->post($url);

            if (! $response->successful()) {
                Log::error('[VoiceAssetService@synthesizeAndStore] TTS HTTP error', [
                    'status'  => $response->status(),
                    'body'    => mb_substr($response->body(), 0, 200),
                    'file'    => $filename,
                ]);
                return false;
            }

            $storagePath = self::PREFIX . "/{$archetypeId}/{$filename}";
            Storage::disk(self::DISK)->put($storagePath, $response->body());

            Log::info('[VoiceAssetService@synthesizeAndStore] WAV almacenado', [
                'path'  => $storagePath,
                'bytes' => strlen($response->body()),
                'ms'    => round((microtime(true) - $t0) * 1000),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[VoiceAssetService@synthesizeAndStore] Excepción', [
                'file'  => $filename,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function fillerCount(): int
    {
        return self::FILLER_COUNT;
    }
}
