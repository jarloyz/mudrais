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
 * Genera los WAVs estáticos del sistema (saludo, silencio, despedida, fillers genéricos).
 * Debe ejecutarse al configurar el sistema por primera vez y cada vez que cambien los textos.
 */
class GenerateStaticVoiceAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(VoiceAssetService $service): void
    {
        Log::info('[GenerateStaticVoiceAssetsJob] Inicio', [
            'total_prompts' => count(VoiceAssetService::STATIC_PROMPTS),
        ]);

        $result = $service->generateStatic();

        Log::info('[GenerateStaticVoiceAssetsJob] Completado', [
            'generated' => $result['generated'],
            'total'     => $result['total'],
        ]);
    }
}
