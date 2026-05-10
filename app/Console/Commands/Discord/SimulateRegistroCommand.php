<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateRegistroCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discord:simulate-registro {--url= : URL del webhook de Discord}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula el comando /registro de Discord';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando comando /registro a {$url}...");

        $response = Http::withHeaders([
            'X-Signature-Ed25519' => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type'   => 2,
            'token'  => 'fake_token_' . time(),
            'member' => ['user' => ['id' => '123456789012345678', 'username' => 'jarloyz']],
            'data'   => ['name' => 'registro'],
        ]);

        $this->info('Status: ' . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('');
        $this->info('→ Deberías ver type:9 con custom_id "mudrais_registro_step_1".');
        $this->info('  Usa discord:simulate-registro-step1-modal para el siguiente paso.');
    }
}
