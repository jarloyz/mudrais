<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulatePingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discord:simulate-ping {--url= : URL del webhook de Discord}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula un PING (Type 1) de validación de Discord';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // En Laravel Sail/Docker, la url de la app puede ser localhost, así que la mandamos directa
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando PING a {$url}...");

        $response = Http::withHeaders([
            'X-Signature-Ed25519' => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type' => 1,
        ]);

        $this->info("Status code: " . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
