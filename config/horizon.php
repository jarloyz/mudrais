<?php

use Illuminate\Support\Str;

return [
    'name'   => env('HORIZON_NAME'),
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    'use'    => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:high'    => 30,
        'redis:index'   => 60,
        'redis:tags'    => 60,
        'redis:default' => 90,
    ],

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced'      => [],
    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit'     => 128,

    // ── Defaults compartidos por todos los supervisors ────────────────────────
    'defaults' => [

        // LLM pesado: ContextOptimizer / IndexAvatarJob (amd-big 120B)
        'supervisor-index' => [
            'connection'          => 'redis',
            'queue'               => ['index'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 6,
            'maxProcesses'        => 25,
            'balanceMaxShift'     => 5,
            'balanceCooldown'     => 3,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 256,
            'tries'               => 2,
            'timeout'             => 300,
            'nice'                => 0,
        ],

        // Tags: warmup LLM (~500 avatars) luego solo embedding — escala agresivo
        'supervisor-tags' => [
            'connection'          => 'redis',
            'queue'               => ['tags'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 10,
            'maxProcesses'        => 60,
            'balanceMaxShift'     => 10,
            'balanceCooldown'     => 2,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 128,
            'tries'               => 3,
            'timeout'             => 90,
            'nice'                => 0,
        ],

        // General: high (Discord urgente), default, sync — jobs rápidos
        'supervisor-general' => [
            'connection'          => 'redis',
            'queue'               => ['high', 'default', 'sync'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 2,
            'maxProcesses'        => 4,
            'balanceMaxShift'     => 1,
            'balanceCooldown'     => 3,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 128,
            'tries'               => 3,
            'timeout'             => 60,
            'nice'                => 0,
        ],
    ],

    // min total: 6+10+2 = 18 | max total: 25+60+4 = 89
    'environments' => [
        'production' => [
            'supervisor-index'   => ['minProcesses' => 6,  'maxProcesses' => 25],
            'supervisor-tags'    => ['minProcesses' => 10, 'maxProcesses' => 60],
            'supervisor-general' => ['minProcesses' => 2,  'maxProcesses' => 4],
        ],
        'local' => [
            'supervisor-index'   => ['minProcesses' => 6,  'maxProcesses' => 25],
            'supervisor-tags'    => ['minProcesses' => 10, 'maxProcesses' => 60],
            'supervisor-general' => ['minProcesses' => 2,  'maxProcesses' => 4],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
