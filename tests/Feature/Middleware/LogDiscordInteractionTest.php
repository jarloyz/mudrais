<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\LogDiscordInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogDiscordInteractionTest extends TestCase
{
    public function test_it_logs_discord_interactions_responses(): void
    {
        Log::shouldReceive('channel')
            ->with('discord')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('Discord Interaction Response', \Mockery::on(function ($context) {
                return $context['discord_user_id'] === 'discord_user_99' &&
                       $context['custom_id'] === 'my_button' &&
                       $context['response_type'] === 4;
            }));

        $middleware = new LogDiscordInteraction();

        $jsonPayload = [
            'id' => '12345',
            'data' => ['custom_id' => 'my_button'],
            'user' => ['id' => 'discord_user_99']
        ];

        $request = Request::create('/api/discord/interactions', 'POST', [], [], [], [], json_encode($jsonPayload));
        $request->headers->set('Content-Type', 'application/json');

        $response = new JsonResponse(['type' => 4, 'data' => ['content' => 'hello']]);

        $middleware->handle($request, function () use ($response) {
            return $response;
        });
    }
}
