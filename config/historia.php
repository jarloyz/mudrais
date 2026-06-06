<?php

return [
    'cache' => [
        'root' => env('HISTORIA_CACHE_ROOT', base_path('../workspace/cache')),
        'prefix' => env('HISTORIA_CACHE_PREFIX', 'historia'),
        'scene_ttl_seconds' => (int) env('HISTORIA_CACHE_SCENE_TTL_SECONDS', 3600),
        'context_ttl_seconds' => (int) env('HISTORIA_CACHE_CONTEXT_TTL_SECONDS', 60),
    ],
    'simple_memory' => [
        'recent_messages' => (int) env('HISTORIA_SIMPLE_MEMORY_RECENT_MESSAGES', 4),
        'batch_message_count' => (int) env('HISTORIA_SIMPLE_MEMORY_BATCH_MESSAGE_COUNT', 6),
        'batch_max_chars' => (int) env('HISTORIA_SIMPLE_MEMORY_BATCH_MAX_CHARS', 4000),
        'summary_max_chars' => (int) env('HISTORIA_SIMPLE_MEMORY_SUMMARY_MAX_CHARS', 3000),
        'scene_opening_max_chars' => (int) env('HISTORIA_SIMPLE_MEMORY_SCENE_OPENING_MAX_CHARS', 1200),
    ],
    // Comandos accesibles sin estar registrado como Player (sin verificación de rol ni energía)
    'discord_public_commands' => ['register', 'help'],

    // Roles mínimos requeridos por comando. Si el comando no aparece → se permite a todos.
    'discord_command_permissions' => [
        'setup'             => ['admin'],
        'setup-onboarding'  => ['admin'],
        'vault-crear'       => ['admin'],
        'vault-config'      => ['admin'],
        'arquetipo'         => ['admin'],
        'player-ban'        => ['admin', 'moderator'],
        'player-kick'       => ['admin', 'moderator'],
        'actividad-fin'     => ['admin', 'moderator'],
        'register'          => ['admin', 'moderator', 'player'],
        'status'            => ['admin', 'moderator', 'player'],
    ],

    // Costo de energía base por comando (0 = gratuito). Puede sobreescribirse por guild en BD.
    'discord_command_energy' => [
        'register'          => 0,
        'status'            => 0,
        'help'              => 0,
        'setup'             => 0,
        'setup-onboarding'  => 0,
        'vault-crear'       => 0,
        'vault-config'      => 0,
        'arquetipo'         => 0,
        'player-ban'        => 0,
        'player-kick'       => 0,
        'actividad-fin'     => 0,
        'search'            => 5,
    ],

    'ai' => [
        'provider' => env('HISTORIA_AI_PROVIDER', 'openrouter'),
        'timeout_ms' => (int) env('HISTORIA_AI_TIMEOUT_MS', 120000),
        'cache_control' => [
            'type' => env('HISTORIA_AI_CACHE_CONTROL_TYPE', 'ephemeral'),
        ],
        'models' => [
            'gatekeeper' => env('HISTORIA_AI_MODEL_GATEKEEPER', 'meta-llama/llama-3-8b-instruct:free'),
            'safety'     => env('HISTORIA_AI_MODEL_SAFETY',     'meta-llama/llama-guard-3-8b'),
            'embedding'  => env('HISTORIA_AI_MODEL_EMBEDDING',  'nvidia/llama-nemotron-embed-vl-1b-v2:free'),
            'librarian'  => env('HISTORIA_AI_MODEL_LIBRARIAN',  'nvidia/llama-nemotron-embed-vl-1b-v2:free'),
            'writer'     => env('HISTORIA_AI_MODEL_WRITER',     'meta-llama/llama-3-8b-instruct:free'),
            'critic'     => env('HISTORIA_AI_MODEL_CRITIC',     'meta-llama/llama-3-8b-instruct:free'),
            'optimizer'  => env('HISTORIA_AI_MODEL_OPTIMIZER',  'meta-llama/llama-3-8b-instruct:free'),
        ],
        'writer' => [
            'temperature' => (float) env('HISTORIA_AI_WRITER_TEMPERATURE', 0.7),
            'max_output_tokens' => (int) env('HISTORIA_AI_WRITER_MAX_OUTPUT_TOKENS', 4000),
            'top_p' => (float) env('HISTORIA_AI_WRITER_TOP_P', 1.0),
            'presence_penalty' => (float) env('HISTORIA_AI_WRITER_PRESENCE_PENALTY', 0.15),
            'frequency_penalty' => (float) env('HISTORIA_AI_WRITER_FREQUENCY_PENALTY', 0.1),
            'style_profile' => (string) env('HISTORIA_AI_WRITER_STYLE_PROFILE', 'cinematico'),
            'style_notes' => (string) env('HISTORIA_AI_WRITER_STYLE_NOTES', ''),
            'response_length' => (string) env('HISTORIA_AI_WRITER_RESPONSE_LENGTH', 'medio'),
        ],
        'librarian' => [
            'temperature' => (float) env('HISTORIA_AI_LIBRARIAN_TEMPERATURE', 0.1),
            'max_output_tokens' => (int) env('HISTORIA_AI_LIBRARIAN_MAX_OUTPUT_TOKENS', 1000),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],
        'google' => [
            'api_key' => env('GOOGLE_API_KEY'),
        ],
    ],
];
