<?php

namespace Tests\Unit\Jobs\Discord;

use App\Jobs\Discord\ProcessStatusJob;
use App\Models\Player;
use App\Services\Discord\Contracts\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_follow_up_with_player_stats(): void
    {
        $player = Player::factory()->create([
            'username' => 'jarloyz',
            'energy'   => 85,
            'coin'     => 12,
            'elo'      => 1150,
        ]);

        $capturedToken     = null;
        $capturedContent   = null;
        $capturedEphemeral = null;

        $mockClient = $this->createMock(DiscordWebhookClient::class);
        $mockClient->expects($this->once())
            ->method('sendFollowUp')
            ->willReturnCallback(function (string $token, string $content, array $extra, bool $ephemeral) use (
                &$capturedToken, &$capturedContent, &$capturedEphemeral
            ) {
                $capturedToken     = $token;
                $capturedContent   = $content;
                $capturedEphemeral = $ephemeral;
            });

        $this->app->instance(DiscordWebhookClient::class, $mockClient);

        // El job recibe discord_id en lugar del modelo Player
        (new ProcessStatusJob($player->discord_id, 'my_test_token'))->handle();

        $this->assertSame('my_test_token', $capturedToken);
        $this->assertTrue($capturedEphemeral, 'El mensaje de status debe ser ephemeral');
        $this->assertStringContainsString($player->username, $capturedContent);
        $this->assertStringContainsString((string) $player->energy, $capturedContent);
        $this->assertStringContainsString((string) $player->coin, $capturedContent);
        $this->assertStringContainsString((string) $player->elo, $capturedContent);
    }

    public function test_it_sends_error_message_when_player_not_found(): void
    {
        $mockClient = $this->createMock(DiscordWebhookClient::class);
        $mockClient->expects($this->once())
            ->method('sendFollowUp')
            ->with(
                $this->anything(),
                $this->stringContains('registro'),
                $this->anything(),
                $this->isTrue(),
            );

        $this->app->instance(DiscordWebhookClient::class, $mockClient);

        (new ProcessStatusJob('discord_id_inexistente', 'token'))->handle();
    }
}
