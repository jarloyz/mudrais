<?php

require __DIR__.'/voice.php';

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
        \App\Http\Middleware\SetDiscordLocale::class,
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

use App\Http\Controllers\Auth\BotBetaInviteController;
use App\Http\Controllers\Auth\BotGammaInviteController;
use App\Http\Controllers\Auth\BotInviteController;
use App\Http\Controllers\Auth\DiscordBetaOAuthController;
use App\Http\Controllers\Auth\DiscordGammaOAuthController;
use App\Http\Controllers\Auth\DiscordOAuthController;
use App\Http\Controllers\Api\GuildLifecycleController;
use App\Http\Controllers\Api\V2\ChatController;
use App\Http\Controllers\Api\V2\MatchmakingController;
use App\Http\Controllers\Api\V2\SceneController;
use Illuminate\Support\Facades\Auth;

// ── Domain-agnostic: webhooks and API ────────────────────────────────────────
// Discord calls these URLs directly — no domain constraint.

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

Route::post('/api/guilds/register', [GuildLifecycleController::class, 'register'])
    ->name('guilds.register')
    ->middleware('throttle:60,1');

// ── app.{base_domain}: OAuth login and bot install flows ─────────────────────
// Constrained to app subdomain so session cookies set here stay isolated from
// the root-domain landing page, and Discord redirect URIs point to a single host.

Route::domain('app.' . config('app.base_domain'))->group(function () {

    // Error page shown after a failed OAuth flow (public, no auth required)
    Route::get('/discord/login/error', fn () => view('discord.login-error'))
        ->name('discord.login.error');

    // ── Production app ────────────────────────────────────────────────────────
    Route::prefix('auth/discord')->name('auth.discord.')->group(function () {
        Route::get('/redirect', [DiscordOAuthController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [DiscordOAuthController::class, 'callback'])->name('callback');
    });

    Route::prefix('invite/bot')->name('invite.bot.')->middleware('auth:player_web')->group(function () {
        Route::get('/redirect', [BotInviteController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [BotInviteController::class, 'callback'])->name('callback');
    });

    // ── Beta app ──────────────────────────────────────────────────────────────
    Route::prefix('auth/discord-beta')->name('auth.discord-beta.')->group(function () {
        Route::get('/redirect', [DiscordBetaOAuthController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [DiscordBetaOAuthController::class, 'callback'])->name('callback');
    });

    Route::prefix('invite/bot-beta')->name('invite.bot-beta.')->middleware('auth:player_web')->group(function () {
        Route::get('/redirect', [BotBetaInviteController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [BotBetaInviteController::class, 'callback'])->name('callback');
    });

    // ── Gamma app ─────────────────────────────────────────────────────────────
    Route::prefix('auth/discord-gamma')->name('auth.discord-gamma.')->group(function () {
        Route::get('/redirect', [DiscordGammaOAuthController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [DiscordGammaOAuthController::class, 'callback'])->name('callback');
    });

    Route::prefix('invite/bot-gamma')->name('invite.bot-gamma.')->middleware('auth:player_web')->group(function () {
        Route::get('/redirect', [BotGammaInviteController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [BotGammaInviteController::class, 'callback'])->name('callback');
    });
});

