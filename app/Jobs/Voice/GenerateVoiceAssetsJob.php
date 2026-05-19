<?php

namespace App\Jobs\Voice;

use App\Services\Voice\VoiceAssetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Genera y almacena los audios WAV pre-sintetizados para las sesiones de voz de un archetype.
 * Se ejecuta en la cola 'voice' después de que se genera/actualiza la pregunta de apertura.
 */
class GenerateVoiceAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(
        public readonly string $archetypeId,
    ) {
        // Cola default: la generación de assets es background, no real-time.
        // ProcessVoiceInterviewTurnJob sí va a 'voice' porque el usuario espera en vivo.
        $this->onQueue('default');
    }

    public function handle(VoiceAssetService $service): void
    {
        Log::info('[GenerateVoiceAssetsJob] Inicio', ['archetype_id' => $this->archetypeId]);

        $result = $service->generateAll($this->archetypeId);

        Log::info('[GenerateVoiceAssetsJob] Completado', [
            'archetype_id'  => $this->archetypeId,
            'opening_ok'    => $result['opening'],
            'fillers_stored'=> $result['fillers'],
        ]);
    }
}
