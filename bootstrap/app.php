<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->is('invite/bot/*')) {
                return route('auth.discord.redirect');
            }
        });
        $middleware->preventRequestForgery(except: [
            'api/discord/interactions',
            'api/v1/*',
            'api/v2/*',
            'auth/discord/*',
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\ContextualLogging::class);
        $middleware->alias([
            'role'           => \App\Http\Middleware\EnsureUserHasRole::class,
            'player.auth'    => \App\Http\Middleware\EnsurePlayerHasToken::class,
            'guild.role'     => \App\Http\Middleware\EnsurePlayerGuildRole::class,
            'discord.guild'  => \App\Http\Middleware\EnsureDiscordGuildRegistered::class,
            'discord.command'=> \App\Http\Middleware\EnsureDiscordCommandPermission::class,
            'discord.energy' => \App\Http\Middleware\EnsurePlayerHasEnergy::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request
        ) {
            if (! $request->expectsJson()) {
                if ($request->is('discord/*') || $request->is('invite/bot/*')) {
                    return redirect()->route('auth.discord.redirect');
                }
            }
        });
    })->create();
