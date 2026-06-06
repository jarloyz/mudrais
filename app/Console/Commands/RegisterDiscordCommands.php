<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterDiscordCommands extends Command
{
    protected $signature = 'discord:register-commands';
    protected $description = 'Registra los Slash Commands de Mudrais en la API de Discord';

    public function handle(): int
    {
        $appId = env('DISCORD_APP_ID');
        $token = env('DISCORD_BOT_TOKEN');

        $commands = [
            // /help
            [
                'name'                      => 'help',
                'description'               => 'Show how MUDRAIS works and the available commands.',
                'name_localizations'        => ['es-ES' => 'ayuda'],
                'description_localizations' => ['es-ES' => 'Muestra cómo funciona MUDRAIS y los comandos disponibles.'],
                'type'                      => 1,
            ],
            // /register (ES: /registro)
            [
                'name'                      => 'register',
                'description'               => 'Start or update your MUDRAIS identity registration.',
                'name_localizations'        => ['es-ES' => 'registro'],
                'description_localizations' => ['es-ES' => 'Inicia o actualiza tu Ficha de Identidad MUDRAIS.'],
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
            // /activity (ES: /actividad) — publish a new group activity search
            [
                'name'                      => 'activity',
                'description'               => 'Publish a new group activity search.',
                'name_localizations'        => ['es-ES' => 'actividad'],
                'description_localizations' => ['es-ES' => 'Gestiona actividades de búsqueda de grupo en el Vault.'],
                'options'                   => [
                    [
                        'name'                      => 'create',
                        'description'               => 'Publish a new group search',
                        'name_localizations'        => ['es-ES' => 'crear'],
                        'description_localizations' => ['es-ES' => 'Publica una nueva búsqueda de grupo'],
                        'type'                      => 1,
                        'options'                   => [
                            [
                                'name'                      => 'main_context',
                                'description'               => 'Main context for this activity',
                                'name_localizations'        => ['es-ES' => 'contexto_principal'],
                                'description_localizations' => ['es-ES' => 'Contexto principal para esta actividad'],
                                'type'                      => 3,
                                'required'                  => true,
                                'autocomplete'              => true,
                            ],
                            [
                                'name'                      => 'secondary_context',
                                'description'               => 'Optional secondary context (can be a different type)',
                                'name_localizations'        => ['es-ES' => 'contexto_secundario'],
                                'description_localizations' => ['es-ES' => 'Segundo contexto opcional (puede ser de tipo diferente)'],
                                'type'                      => 3,
                                'required'                  => false,
                                'autocomplete'              => true,
                            ],
                        ],
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
                        'name'                      => 'target',
                        'description'               => 'Search type (partner, character, activity, etc.)',
                        'name_localizations'        => ['es-ES' => 'objetivo'],
                        'description_localizations' => ['es-ES' => 'El tipo de búsqueda (partner, personaje, actividad, etc.)'],
                        'type'                      => 3,
                        'required'                  => true,
                        'autocomplete'              => true,
                    ],
                    [
                        'name'                      => 'prompt',
                        'description'               => 'Additional terms to improve the semantic search',
                        'name_localizations'        => ['es-ES' => 'texto'],
                        'description_localizations' => ['es-ES' => 'Términos adicionales para mejorar la búsqueda semántica'],
                        'type'                      => 3,
                        'required'                  => false,
                    ],
                    [
                        'name'                      => 'period',
                        'description'               => 'Filter by publication time',
                        'name_localizations'        => ['es-ES' => 'periodo'],
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

        $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($response->successful()) {
            $this->info('¡Comandos registrados exitosamente! Revisa el JSON de arriba para validar nombres y localizaciones.');
            return self::SUCCESS;
        }

        $this->error('Error al registrar. Status: ' . $response->status());
        return self::FAILURE;
    }
}
