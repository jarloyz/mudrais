<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateRegistroStep1ModalCommand extends Command
{
    protected $signature = 'discord:simulate-registro-step1-modal
                            {--edad=28 : Edad a enviar}
                            {--url= : URL del endpoint de interacciones}';

    protected $description = 'Simula el submit del modal de registro paso 1';

    public function handle(): void
    {
        $url  = $this->option('url') ?: url('/api/discord/interactions');
        $edad = $this->option('edad');

        $this->info("Enviando submit registro step 1 (edad={$edad}) a {$url}...");

        $response = Http::withHeaders([
            'X-Signature-Ed25519'    => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type'   => 5,
            'token'  => 'fake_token_' . time(),
            'member' => ['user' => ['id' => '123456789012345678', 'username' => 'jarloyz']],
            'data'   => [
                'custom_id'  => 'mudrais_registro_step_1',
                'components' => [
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'edad',        'value'  => $edad]]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'nacionalidad', 'value'  => 'México']]],
                    ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'experiencia',  'values' => ['3']]]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'horarios',     'value'  => 'Lunes a Viernes 21:00-00:00 UTC-6']]],
                    ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'extension',    'values' => ['4']]]],
                ],
            ],
        ]);

        $this->info('Status: ' . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
