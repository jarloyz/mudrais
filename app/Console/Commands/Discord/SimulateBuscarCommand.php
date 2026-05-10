<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SimulateBuscarCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discord:simulate-buscar
                            {--url= : URL del webhook de Discord}
                            {--contexto=jugador de fantasía épica comprometido con la narrativa : Contexto de la búsqueda}
                            {--experiencia=Veterano : Filtro opcional de experiencia}
                            {--verbosidad=Alta/Biblias : Filtro opcional de verbosidad}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula el comando /buscar-partida de Discord';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->option('url') ?: url('/api/discord/interactions');

        $this->info("Enviando comando /buscar-partida a {$url}...");

        $options = [
            [
                'name' => 'contexto',
                'value' => $this->option('contexto'),
            ],
        ];

        if ($this->option('experiencia')) {
            $options[] = [
                'name' => 'experiencia',
                'value' => $this->option('experiencia'),
            ];
        }

        if ($this->option('verbosidad')) {
            $options[] = [
                'name' => 'verbosidad',
                'value' => $this->option('verbosidad'),
            ];
        }

        $response = Http::withHeaders([
            'X-Signature-Ed25519' => 'dummy_signature',
            'X-Signature-Timestamp' => (string) time(),
        ])->post($url, [
            'type'   => 2,
            'token'  => 'fake_token_' . time(),
            'member' => [
                'user' => [
                    'id' => '123456789012345678',
                    'username' => 'jarloyz',
                ],
            ],
            'data' => [
                'name' => 'buscar-partida',
                'options' => $options,
            ],
        ]);

        $this->info("Status code: " . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
