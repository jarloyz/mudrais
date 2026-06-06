<?php

namespace Tests\Feature\Discord;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = $this->createMock(\App\Services\Discord\Contracts\DiscordSignatureValidator::class);
        $mock->method('isValid')->willReturn(true);
        $this->app->instance(\App\Services\Discord\Contracts\DiscordSignatureValidator::class, $mock);
    }

    private function helpPayload(string $locale = 'es-ES', ?string $guildId = null): array
    {
        return [
            'type'     => 2,
            'locale'   => $locale,
            'guild_id' => $guildId ?? 'guild_test_001',
            'token'    => 'test_token',
            'member'   => ['user' => ['id' => '999888777666']],
            'data'     => ['name' => 'help'],
        ];
    }

    // T1 — /help sin player registrado → embed ephemeral (comando público)
    public function test_help_command_works_without_registered_player(): void
    {
        $response = $this->postJson('/api/discord/interactions', $this->helpPayload());

        $response->assertOk()
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64);

        $embeds = $response->json('data.embeds');
        $this->assertNotEmpty($embeds);
    }

    // T2 — /help con locale es-ES → embed en español
    public function test_help_command_returns_spanish_embed_for_es_locale(): void
    {
        $response = $this->postJson('/api/discord/interactions', $this->helpPayload('es-ES'));

        $response->assertOk();

        $title = $response->json('data.embeds.0.title');
        $this->assertSame(__('discord.help_title'), $title);
    }

    // T3 — /help con locale en-US → embed en inglés
    public function test_help_command_returns_english_embed_for_en_locale(): void
    {
        $response = $this->postJson('/api/discord/interactions', $this->helpPayload('en-US'));

        $response->assertOk();

        $title = $response->json('data.embeds.0.title');

        // Forzamos el locale en el assert para comparar contra la traducción correcta
        app()->setLocale('en');
        $this->assertSame(__('discord.help_title'), $title);
    }

    // T4 — /help con locale desconocido → fallback a español
    public function test_help_command_falls_back_to_spanish_for_unknown_locale(): void
    {
        $response = $this->postJson('/api/discord/interactions', $this->helpPayload('pt-BR'));

        $response->assertOk();

        $title = $response->json('data.embeds.0.title');

        app()->setLocale('es');
        $this->assertSame(__('discord.help_title'), $title);
    }

    // T5 — /help en DM (sin guild_id) → también funciona
    public function test_help_command_works_in_dm_without_guild(): void
    {
        $payload = [
            'type'   => 2,
            'locale' => 'es-ES',
            'token'  => 'test_token',
            'user'   => ['id' => '999888777666'],
            'data'   => ['name' => 'help'],
        ];

        $response = $this->postJson('/api/discord/interactions', $payload);

        $response->assertOk()
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64);
    }
}
