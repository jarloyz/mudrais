<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateFichaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discord:simulate-ficha {--url= : URL del webhook de Discord}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula el comando /ficha de Discord';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando comando /ficha a {$url}...");

        $response = Http::withHeaders([
            'X-Signature-Ed25519' => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type' => 2,
            'member' => [
                'user' => [
                    'id' => '123456789012345678',
                    'username' => 'jarloyz',
                ],
            ],
            'data' => [
                'name' => 'ficha',
            ],
        ]);

        $this->info("Status code: " . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
