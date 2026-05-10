<?php

use App\Http\Controllers\Api\DiscordController;
use App\Http\Controllers\Api\SystemHealthController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V2\ContinuityController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'bootstrapped',
        'docs' => [
            'architecture' => 'docs/architecture.md',
            'migration' => 'docs/migration_from_node_v2.md',
        ],
    ]);
});

Route::redirect('/chat', '/app/chat')->name('chat');

Route::get('/api/health', SystemHealthController::class);



// V1 Players API
Route::post('/api/v1/players', [PlayerController::class, 'register'])
    ->middleware('throttle:60,1');
Route::post('/api/discord/interactions', [DiscordController::class, 'handle'])
    ->middleware([
        \App\Http\Middleware\LogDiscordInteraction::class,
        \App\Http\Middleware\VerifyDiscordSignature::class,
        \App\Http\Middleware\EnsureDiscordGuildRegistered::class,
        \App\Http\Middleware\EnsureDiscordCommandPermission::class,
        \App\Http\Middleware\EnsurePlayerHasEnergy::class,
    ]);

/**
 * Stub local para capturar los follow-ups que los Jobs envían a Discord.
 * Activo solo en entorno local — devuelve 404 en producción.
 *
 * URL equivalente a: PATCH https://discord.com/api/v10/webhooks/{appId}/{token}/messages/@original
 */
Route::patch('/local-discord-stub/webhooks/{appId}/{token}/messages/@original',
    function (\Illuminate\Http\Request $request, string $appId, string $token) {
        if (!app()->environment('local')) {
            abort(404);
        }

        \Illuminate\Support\Facades\Log::info('=== Discord Follow-up Stub ===', [
            'app_id'  => $appId,
            'token'   => $token,
            'payload' => $request->all(),
        ]);

        return response()->json(['id' => '@original']);
    }
);

use App\Http\Controllers\Auth\BotInviteController;
use App\Http\Controllers\Auth\DiscordOAuthController;
use App\Http\Controllers\Api\GuildLifecycleController;
use App\Http\Controllers\Api\V2\ChatController;
use App\Http\Controllers\Api\V2\MatchmakingController;
use App\Http\Controllers\Api\V2\SceneController;
use Illuminate\Support\Facades\Auth;

Route::prefix('api/v2')->group(function () {
    Route::post('/chat', [ChatController::class, 'store']);
    Route::post('/chat/stream', [ChatController::class, 'stream']);
    Route::post('/activity/bootstrap', [SceneController::class, 'bootstrap']);
    Route::post('/activity/create', [SceneController::class, 'store']);
    Route::post('/activity/avatars/attach', [SceneController::class, 'attachCharacter']);
    Route::post('/continuity/turn', [ContinuityController::class, 'turn']);
    Route::post('/continuity/branch', [ContinuityController::class, 'branch']);
    Route::post('/continuity/checkout', [ContinuityController::class, 'checkout']);
    Route::post('/continuity/rewind', [ContinuityController::class, 'rewind']);
    Route::post('/continuity/switch', [ContinuityController::class, 'switch']);
    Route::get('/timeline', [SceneController::class, 'timeline']);
    Route::get('/activity/context', [SceneController::class, 'context']);
    Route::get('/activity/state', [SceneController::class, 'sceneState']);
    Route::post('/activities/{id}/fork', [SceneController::class, 'fork']);
    Route::post('/matchmaking/search', [MatchmakingController::class, 'search']);
});

// Guild lifecycle — llamado por el bot al ser invitado a un servidor
Route::post('/api/guilds/register', [GuildLifecycleController::class, 'register'])
    ->name('guilds.register')
    ->middleware('throttle:60,1');

// Ruta A: login de usuario (scope: identify email)
Route::prefix('auth/discord')->name('auth.discord.')->group(function () {
    Route::get('/redirect', [DiscordOAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [DiscordOAuthController::class, 'callback'])->name('callback');
});

// Error de login OAuth (público)
Route::get('/discord/login/error', fn () => view('discord.login-error'))
    ->name('discord.login.error');

// Ruta B: instalación del bot — el redirect y callback viven fuera del panel Filament
// porque Discord redirige a estas URLs directamente.
Route::prefix('invite/bot')->name('invite.bot.')->middleware('auth:player_web')->group(function () {
    Route::get('/redirect', [BotInviteController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [BotInviteController::class, 'callback'])->name('callback');
});

