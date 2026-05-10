<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateSearchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discord:simulate-search
                            {--url= : URL del endpoint de interacciones}
                            {--objetivo=2 : ID del ArchetypeEntityType (2 = Rol 1x1)}
                            {--texto=un rol de iglesia y asesinato misterioso : Texto de búsqueda}
                            {--guild=1493874482404790303 : ID de la Guild}
                            {--channel=1497495572137906187 : ID del Canal (Vault)}
                            {--user=220800677218091008 : ID de Discord del usuario}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula el comando /search de Discord';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando comando /search a {$url}...");

        $interaction = [
            'type'   => 2,
            'token'  => 'fake_token_' . time(),
            'guild_id' => $this->option('guild'),
            'channel_id' => $this->option('channel'),
            'member' => [
                'user' => [
                    'id' => $this->option('user'),
                    'username' => 'testuser',
                ],
            ],
            'data' => [
                'name' => 'search',
                'options' => [
                    [
                        'name' => 'objetivo',
                        'type' => 3,
                        'value' => $this->option('objetivo'),
                    ],
                    [
                        'name' => 'texto',
                        'type' => 3,
                        'value' => $this->option('texto'),
                    ],
                ],
            ],
        ];

        $response = Http::withHeaders([
            'X-Signature-Ed25519' => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, $interaction);

        $this->info("Status code: " . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
