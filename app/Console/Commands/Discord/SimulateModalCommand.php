<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateModalCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discord:simulate-modal {--url= : URL del webhook de Discord}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula el envío del modal de la ficha (Type 5)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando submit del modal de ficha a {$url}...");

        $valueText = "**DATOS BÁSICOS**\n* Edad: 28\n* Nacionalidad: México\n* Experiencia: Veterano\n\n**LOGÍSTICA Y ESTILO**\n* Horarios disponibles: Lunes a viernes 21:00-00:00 UTC-6\n* Extensión: Alta/Biblias\n* Líneas Rojas: gore\n\n**TUS AFINIDADES**\n1. Fantasía épica\n2. Misterio sobrenatural\n3. Horror cósmico\n\n**ESTILO NARRATIVO**\nLlevo 6 años haciendo rol narrativo. Me especializo en personajes con arcos complejos.";

        $response = Http::withHeaders([
            'X-Signature-Ed25519' => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type'   => 5,
            'token'  => 'fake_token_' . time(),
            'member' => [
                'user' => [
                    'id' => '123456789012345678',
                    'username' => 'jarloyz',
                ],
            ],
            'data' => [
                'custom_id' => 'mudrais_ficha',
                'components' => [
                    [
                        'type' => 1,
                        'components' => [
                            [
                                'type' => 4,
                                'custom_id' => 'profile_text',
                                'value' => $valueText,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->info("Status code: " . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
