<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * @deprecated Duplicado obsoleto. Usa RegisterDiscordCommands (raíz Commands/) que incluye
 *             los slash commands actuales: activity, actividad, search, buscar-partner.
 */
class RegisterCommands extends Command
{
    protected $signature = 'discord:register-commands-legacy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Registra los Slash Commands globales de MUDRAIS en la API de Discord';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $appId = env('DISCORD_APP_ID');
        $token = env('DISCORD_BOT_TOKEN');

        if (!$appId || !$token) {
            $this->error('Faltan variables de entorno: DISCORD_APP_ID o DISCORD_BOT_TOKEN en tu archivo .env.');
            return Command::FAILURE;
        }

        $url = "https://discord.com/api/v10/applications/{$appId}/commands";

        // Definición de los Slash Commands según el doc mudrais_identidad_matchmaking.md
        $commands = [
            [
                'name' => 'status',
                'description' => 'Muestra el estado actual de tus sistemas (energía, monedas, ELO).',
                'type' => 1, // CHAT_INPUT
            ],
            [
                'name' => 'registro',
                'description' => 'Muestra la plantilla oficial de la Ficha de Identidad MUDRAIS (mensaje efímero).',
                'type' => 1,
            ],
            [
                'name' => 'ficha',
                'description' => 'Abre un modal para que pegues tu Ficha de Identidad MUDRAIS rellenada.',
                'type' => 1,
            ],
            [
                'name'        => 'create',
                'description' => 'Add a new element (Character, Location, Lore) to this Vault',
                'type'        => 1,
                'options'     => [
                    [
                        'name'         => 'type',
                        'description'  => 'What do you want to create?',
                        'type'         => 3,
                        'required'     => true,
                        'autocomplete' => true,
                    ],
                ],
            ],
            [
                'name' => 'buscar-partida',
                'description' => 'Busca jugadores compatibles usando el Matchmaking Híbrido.',
                'type' => 1,
                'options' => [
                    [
                        'name' => 'contexto',
                        'description' => 'Descripción en lenguaje natural del jugador ideal que buscas',
                        'type' => 3, // STRING
                        'required' => true,
                    ],
                    [
                        'name' => 'experiencia',
                        'description' => 'Filtro exacto de experiencia',
                        'type' => 3, // STRING
                        'required' => false,
                        'choices' => [
                            ['name' => 'Novato', 'value' => 'Novato'],
                            ['name' => 'Veterano', 'value' => 'Veterano'],
                            ['name' => 'Máster', 'value' => 'Máster'],
                        ]
                    ],
                    [
                        'name' => 'verbosidad',
                        'description' => 'Filtro exacto de verbosidad (longitud de texto preferida)',
                        'type' => 3, // STRING
                        'required' => false,
                        'choices' => [
                            ['name' => 'Alta/Biblias', 'value' => 'Alta/Biblias'],
                            ['name' => 'Media', 'value' => 'Media'],
                            ['name' => 'Baja/Acción rápida', 'value' => 'Baja/Acción rápida'],
                        ]
                    ],
                ]
            ],
        ];

        $this->info("Registrando comandos globales en Discord para la App ID: {$appId}...");

        $response = Http::withToken($token, 'Bot')->put($url, $commands);

        if ($response->successful()) {
            $this->info("¡Comandos registrados correctamente!");
            $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $this->error("Error al registrar los comandos. Status: " . $response->status());
        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Command::FAILURE;
    }
}
