<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateRegistroStep2ModalCommand extends Command
{
    protected $signature = 'discord:simulate-registro-step2-modal {--url= : URL del endpoint de interacciones}';

    protected $description = 'Simula el submit del modal de registro paso 2';

    public function handle(): void
    {
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando submit registro step 2 a {$url}...");

        $response = Http::withHeaders([
            'X-Signature-Ed25519'    => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type'   => 5,
            'token'  => 'fake_token_' . time(),
            'member' => ['user' => ['id' => '123456789012345678', 'username' => 'jarloyz']],
            'data'   => [
                'custom_id'  => 'mudrais_registro_step_2',
                'components' => [
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'red_lines',          'value' => 'gore, violencia extrema']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'yellow_lines',       'value' => 'romance explícito, tortura']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'preferences',        'value' => 'Fantasía épica, Misterio sobrenatural, Horror cósmico']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'style',              'value' => '3ra persona, drama psicológico, desarrollo lento, personajes complejos']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'input_biografia_pg', 'value' => 'Llevo 6 años haciendo rol narrativo. Me especializo en arcos de personaje complejos.']]],
                ],
            ],
        ]);

        $this->info('Status: ' . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
