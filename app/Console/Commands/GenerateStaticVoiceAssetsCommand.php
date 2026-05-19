<?php

namespace App\Console\Commands;

use App\Services\Voice\VoiceAssetService;
use Illuminate\Console\Command;

class GenerateStaticVoiceAssetsCommand extends Command
{
    protected $signature   = 'voice:generate-static';
    protected $description = 'Genera los WAVs estáticos del sistema de voz (saludo, despedida, fillers genéricos).';

    public function handle(VoiceAssetService $service): int
    {
        $this->info('Generando WAVs estáticos...');
        $this->table(
            ['Archivo', 'Texto'],
            collect(VoiceAssetService::STATIC_PROMPTS)
                ->map(fn($text, $file) => [$file, mb_substr($text, 0, 60)])
                ->values()
                ->toArray(),
        );

        $result = $service->generateStatic();

        if ($result['generated'] === $result['total']) {
            $this->info("✓ {$result['generated']}/{$result['total']} WAVs generados correctamente.");
            return self::SUCCESS;
        }

        $this->warn("⚠ Solo {$result['generated']}/{$result['total']} WAVs generados. Revisa los logs.");
        return self::FAILURE;
    }
}
