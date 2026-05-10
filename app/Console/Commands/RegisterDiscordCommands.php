<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterDiscordCommands extends Command
{
    // El comando que escribirás en la terminal
    protected $signature = 'discord:register-commands';
    protected $description = 'Registra los Slash Commands de Mudrais en la API de Discord';

    public function handle()
    {
        $appId = env('DISCORD_APP_ID');
        $token = env('DISCORD_BOT_TOKEN');

        $commands = [
            [
                'name'        => 'status',
                'description' => 'Muestra tus estadísticas, ELO y energía actual.',
                'type'        => 1,
            ],
            [
                'name'        => 'registro',
                'description' => 'Inicia el registro o actualización de tu Ficha de Identidad.',
                'type'        => 1,
            ],
            [
                'name'        => 'ficha',
                'description' => 'Abre el modal interactivo para enviar tu ficha rellena.',
                'type'        => 1,
            ],
            [
                'name'        => 'buscar-partner',
                'description' => 'Analiza tu Ficha de Identidad y encuentra jugadores con una vibra y afinidad compatibles.',
                'type'        => 1,
            ],
            [
                'name'        => 'create_vault',
                'description' => 'Inicializa un nuevo Vault dentro de un Archetype',
                'default_member_permissions' => '16',
                'options'     => [
                    [
                        'name'         => 'archetype',
                        'description'  => 'Selecciona el Archetype base',
                        'type'         => 3,
                        'required'     => true,
                        'autocomplete' => true,
                    ],
                ],
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
                'name'        => 'activity',
                'description' => 'Start looking for a partner or group for a specific Vault',
                'type'        => 1,
                'options'     => [
                    [
                        'name'         => 'vault',
                        'description'  => 'Search and select the Vault you want to play in',
                        'type'         => 3,
                        'required'     => true,
                        'autocomplete' => true,
                    ],
                ],
            ],
            [
                'name'        => 'actividad',
                'description' => 'Gestiona actividades de búsqueda de grupo en el Vault',
                'options'     => [
                    [
                        'name'        => 'crear',
                        'description' => 'Publica una nueva búsqueda de grupo',
                        'type'        => 1,
                        'options'     => [
                            [
                                'name'         => 'contexto_principal',
                                'description'  => 'Contexto principal para esta actividad',
                                'type'         => 3,
                                'required'     => true,
                                'autocomplete' => true,
                            ],
                            [
                                'name'         => 'contexto_secundario',
                                'description'  => 'Segundo contexto opcional (puede ser de tipo diferente)',
                                'type'         => 3,
                                'required'     => false,
                                'autocomplete' => true,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'search',
                'description' => 'Busca partners, personajes o actividades compatibles usando tu perfil',
                'type'        => 1,
                'options'     => [
                    [
                        'name'         => 'objetivo',
                        'description'  => 'El tipo de búsqueda (partner, personaje, rol 1x1, etc.)',
                        'type'         => 3,
                        'required'     => true,
                        'autocomplete' => true,
                    ],
                    [
                        'name'        => 'texto',
                        'description' => 'Términos adicionales para mejorar la búsqueda semántica',
                        'type'        => 3,
                        'required'    => false,
                    ],
                    [
                        'name'        => 'periodo',
                        'description' => 'Filtrar por tiempo de publicación',
                        'type'        => 3,
                        'required'    => false,
                        'choices'     => [
                            ['name' => 'Últimas 24 horas', 'value' => '24h'],
                            ['name' => 'Últimos 7 días', 'value' => '7d'],
                            ['name' => 'Últimos 30 días', 'value' => '30d'],
                        ],
                    ],
                ],
            ],
        ];

        $this->info('Enviando comandos a Discord...');

        // Hacemos el POST a la API oficial de Discord
        $response = Http::withHeaders([
            'Authorization' => 'Bot ' . $token,
            'Content-Type' => 'application/json',
        ])->put("https://discord.com/api/v10/applications/{$appId}/commands", $commands);

        if ($response->successful()) {
            $this->info('¡Comandos registrados exitosamente!');
        } else {
            $this->error('Error al registrar: ' . $response->body());
        }
    }
}
