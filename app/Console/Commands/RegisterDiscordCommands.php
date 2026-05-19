<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterDiscordCommands extends Command
{
    protected $signature = 'discord:register-commands';
    protected $description = 'Registra los Slash Commands de Mudrais en la API de Discord';

    public function handle()
    {
        $appId = env('DISCORD_APP_ID');
        $token = env('DISCORD_BOT_TOKEN');

        $commands = [
            // /register (ES: /registro)
            [
                'name'                      => 'register',
                'description'               => 'Start or update your MUDRAIS identity registration.',
                'name_localizations'        => ['es-ES' => 'registro'],
                'description_localizations' => ['es-ES' => 'Inicia o actualiza tu Ficha de Identidad MUDRAIS.'],
                'type'                      => 1,
            ],
            // /profile (ES: /ficha)
            [
                'name'                      => 'profile',
                'description'               => 'Submit your MUDRAIS identity file via modal.',
                'name_localizations'        => ['es-ES' => 'ficha'],
                'description_localizations' => ['es-ES' => 'Abre el modal interactivo para enviar tu ficha rellena.'],
                'type'                      => 1,
            ],
            // /status
            [
                'name'                      => 'status',
                'description'               => 'Show your stats, ELO and current energy.',
                'description_localizations' => ['es-ES' => 'Muestra tus estadísticas, ELO y energía actual.'],
                'type'                      => 1,
            ],
            // /create-vault (ES: /crear-vault) — admin only
            [
                'name'                      => 'create-vault',
                'description'               => 'Initialize a new Vault within an Archetype.',
                'name_localizations'        => ['es-ES' => 'crear-vault'],
                'description_localizations' => ['es-ES' => 'Inicializa un nuevo Vault dentro de un Archetype.'],
                'default_member_permissions' => '16',
                'options'                   => [
                    [
                        'name'                      => 'archetype',
                        'description'               => 'Select the base Archetype',
                        'description_localizations' => ['es-ES' => 'Selecciona el Archetype base'],
                        'type'                      => 3,
                        'required'                  => true,
                        'autocomplete'              => true,
                    ],
                ],
            ],
            // /create
            [
                'name'                      => 'create',
                'description'               => 'Add a new element (Character, Location, Lore) to this Vault.',
                'description_localizations' => ['es-ES' => 'Añade un nuevo elemento (Personaje, Lugar, Lore) a este Vault.'],
                'type'                      => 1,
                'options'                   => [
                    [
                        'name'                      => 'type',
                        'description'               => 'What do you want to create?',
                        'description_localizations' => ['es-ES' => '¿Qué quieres crear?'],
                        'type'                      => 3,
                        'required'                  => true,
                        'autocomplete'              => true,
                    ],
                ],
            ],
            // /activity — find partner/group for a Vault
            [
                'name'                      => 'activity',
                'description'               => 'Start looking for a partner or group for a specific Vault.',
                'description_localizations' => ['es-ES' => 'Empieza a buscar un partner o grupo para un Vault específico.'],
                'type'                      => 1,
                'options'                   => [
                    [
                        'name'                      => 'vault',
                        'description'               => 'Search and select the Vault you want to play in',
                        'description_localizations' => ['es-ES' => 'Busca y selecciona el Vault en el que quieres jugar'],
                        'type'                      => 3,
                        'required'                  => true,
                        'autocomplete'              => true,
                    ],
                ],
            ],
            // /actividad — publish a new group activity search
            [
                'name'                      => 'actividad',
                'description'               => 'Publish a new group activity search.',
                'description_localizations' => ['es-ES' => 'Gestiona actividades de búsqueda de grupo en el Vault.'],
                'options'                   => [
                    [
                        'name'                      => 'crear',
                        'description'               => 'Publish a new group search',
                        'description_localizations' => ['es-ES' => 'Publica una nueva búsqueda de grupo'],
                        'type'                      => 1,
                        'options'                   => [
                            [
                                'name'                      => 'contexto_principal',
                                'description'               => 'Main context for this activity',
                                'description_localizations' => ['es-ES' => 'Contexto principal para esta actividad'],
                                'type'                      => 3,
                                'required'                  => true,
                                'autocomplete'              => true,
                            ],
                            [
                                'name'                      => 'contexto_secundario',
                                'description'               => 'Optional secondary context (can be a different type)',
                                'description_localizations' => ['es-ES' => 'Segundo contexto opcional (puede ser de tipo diferente)'],
                                'type'                      => 3,
                                'required'                  => false,
                                'autocomplete'              => true,
                            ],
                        ],
                    ],
                ],
            ],
            // /interview (ES: /entrevista) — Dynamic Interviewer Agent
            [
                'name'                      => 'interview',
                'description'               => 'Fill your archetype profile through an AI-guided conversation.',
                'name_localizations'        => ['es-ES' => 'entrevista'],
                'description_localizations' => ['es-ES' => 'Completa tu ficha de arquetipo mediante una conversación guiada por IA.'],
                'type'                      => 1,
                'options'                   => [
                    [
                        'name'                      => 'respuesta',
                        'description'               => 'Your answer to the current interview question',
                        'name_localizations'        => ['es-ES' => 'respuesta'],
                        'description_localizations' => ['es-ES' => 'Tu respuesta a la pregunta actual de la entrevista'],
                        'type'                      => 3,
                        'required'                  => false,
                    ],
                    [
                        'name'                      => 'reiniciar',
                        'description'               => 'Force-restart your interview session from scratch.',
                        'name_localizations'        => ['es-ES' => 'reiniciar'],
                        'description_localizations' => ['es-ES' => 'Fuerza el reinicio de tu sesión de entrevista desde cero.'],
                        'type'                      => 5,
                        'required'                  => false,
                    ],
                ],
            ],
            // /search (ES: /buscar) — absorbs buscar-partner
            [
                'name'                      => 'search',
                'description'               => 'Find compatible partners, characters or activities using your profile.',
                'name_localizations'        => ['es-ES' => 'buscar'],
                'description_localizations' => ['es-ES' => 'Encuentra partners, personajes o actividades compatibles usando tu perfil.'],
                'type'                      => 1,
                'options'                   => [
                    [
                        'name'                      => 'objetivo',
                        'description'               => 'Search type (partner, character, activity, etc.)',
                        'description_localizations' => ['es-ES' => 'El tipo de búsqueda (partner, personaje, actividad, etc.)'],
                        'type'                      => 3,
                        'required'                  => true,
                        'autocomplete'              => true,
                    ],
                    [
                        'name'                      => 'texto',
                        'description'               => 'Additional terms to improve the semantic search',
                        'description_localizations' => ['es-ES' => 'Términos adicionales para mejorar la búsqueda semántica'],
                        'type'                      => 3,
                        'required'                  => false,
                    ],
                    [
                        'name'                      => 'periodo',
                        'description'               => 'Filter by publication time',
                        'description_localizations' => ['es-ES' => 'Filtrar por tiempo de publicación'],
                        'type'                      => 3,
                        'required'                  => false,
                        'choices'                   => [
                            [
                                'name'               => 'Last 24 hours',
                                'name_localizations' => ['es-ES' => 'Últimas 24 horas'],
                                'value'              => '24h',
                            ],
                            [
                                'name'               => 'Last 7 days',
                                'name_localizations' => ['es-ES' => 'Últimos 7 días'],
                                'value'              => '7d',
                            ],
                            [
                                'name'               => 'Last 30 days',
                                'name_localizations' => ['es-ES' => 'Últimos 30 días'],
                                'value'              => '30d',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->info('Enviando comandos a Discord...');

        $response = Http::withHeaders([
            'Authorization' => 'Bot ' . $token,
            'Content-Type'  => 'application/json',
        ])->put("https://discord.com/api/v10/applications/{$appId}/commands", $commands);

        if ($response->successful()) {
            $this->info('¡Comandos registrados exitosamente!');
        } else {
            $this->error('Error al registrar: ' . $response->body());
        }
    }
}
