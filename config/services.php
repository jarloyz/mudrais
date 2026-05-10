<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-pro'),
        'qa_model' => env('OPENROUTER_QA_MODEL', 'google/gemini-2.5-flash'),
    ],

    'discord' => [
        'app_id'          => env('DISCORD_APP_ID'),
        'bot_token'       => env('DISCORD_BOT_TOKEN'),
        'client_id'       => env('DISCORD_CLIENT_ID'),
        'client_secret'   => env('DISCORD_CLIENT_SECRET'),
        'redirect'        => env('DISCORD_REDIRECT_URI'),
        'bot_redirect'    => env('DISCORD_BOT_REDIRECT_URI'),
        'bot_permissions' => env('DISCORD_BOT_PERMISSIONS', '0'),
        'stateless'       => env('DISCORD_STATELESS', false),
        // URL interna usada por los jobs dentro de Docker para llamar al stub local.
        // Independiente de APP_URL, que puede apuntar a ngrok.
        'internal_url'    => env('APP_INTERNAL_URL', 'http://laravel.test'),
    ],

    'qdrant' => [
        'host'              => env('QDRANT_HOST', 'localhost'),
        'port'              => env('QDRANT_PORT', '6333'),
        'api_key'           => env('QDRANT_API_KEY', ''),
        'collection_name'    => env('QDRANT_LORE_COLLECTION', 'historia_lore'),
        'player_collection'  => env('QDRANT_PLAYER_COLLECTION', 'player_matchmaking'),
        'profiles_collection'=> env('QDRANT_PROFILES_COLLECTION', 'players_profiles'),
    ],

    'ai' => [
        'embedding_model' => env('EMBEDDING_MODEL', 'nomic-ai/nomic-embed-text-v1.5'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'moderation_timeout' => env('OPENAI_MODERATION_TIMEOUT', 5),
    ],

];
