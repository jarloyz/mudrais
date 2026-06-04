<?php

namespace Tests\Feature\Discord;

use App\Domains\Community\Models\Guild;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuildBotAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = $this->createMock(\App\Services\Discord\Contracts\DiscordSignatureValidator::class);
        $mock->method('isValid')->willReturn(true);
        $this->app->instance(\App\Services\Discord\Contracts\DiscordSignatureValidator::class, $mock);
    }

    private function createVaultPayload(string $guildId, string $userId = '111222333444'): array
    {
        return [
            'type'     => 2,
            'guild_id' => $guildId,
            'token'    => 'test_token',
            'member'   => ['user' => ['id' => $userId]],
            'data'     => [
                'name'    => 'create-vault',
                'options' => [['name' => 'archetype', 'value' => 'some-archetype-id']],
            ],
        ];
    }

    // T1 — Guild auto-creada con default false → bloqueado
    // EnsureDiscordGuildRegistered auto-crea la guild si no existe, respetando el AppSetting
    public function test_create_vault_blocked_when_auto_created_guild_default_is_false(): void
    {
        // Establecer default global en false antes de que se auto-cree la guild
        AppSetting::set('guild_bot_allowed_default', 'false');

        $response = $this->postJson('/api/discord/interactions', $this->createVaultPayload('brand_new_guild_001'));

        $response->assertOk()
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64);

        $content = $response->json('data.content');
        $this->assertIsString($content);
        $this->assertStringContainsString('registrada', $content);
    }

    // T2 — Guild existe pero is_bot_allowed = false → bloqueado
    public function test_create_vault_blocked_when_guild_bot_not_allowed(): void
    {
        Guild::create([
            'discord_guild_id' => 'guild_blocked_456',
            'is_bot_allowed'   => false,
        ]);

        $response = $this->postJson('/api/discord/interactions', $this->createVaultPayload('guild_blocked_456'));

        $response->assertOk()
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64);

        $content = $response->json('data.content');
        $this->assertStringContainsString('registrada', $content);
    }

    // T3 — Guild existe con is_bot_allowed = true → NO devuelve guild_not_registered
    public function test_create_vault_passes_guild_check_when_allowed(): void
    {
        Guild::create([
            'discord_guild_id' => 'guild_allowed_789',
            'is_bot_allowed'   => true,
        ]);

        $response = $this->postJson('/api/discord/interactions', $this->createVaultPayload('guild_allowed_789'));

        $response->assertOk();

        // No debe devolver el bloqueo de guild — puede fallar por player no registrado
        // pero NO por acceso de guild
        $content = $response->json('data.content') ?? '';
        $this->assertStringNotContainsString('registrada', $content);
    }

    // T4 — Otros comandos NO son bloqueados aunque la guild no exista en DB
    public function test_other_commands_not_blocked_by_guild_access_check(): void
    {
        // 'registro' es un comando público — no debe disparar el check de guild access
        $payload = [
            'type'     => 2,
            'guild_id' => 'nonexistent_guild_000',
            'token'    => 'test_token_registro',
            'member'   => ['user' => ['id' => '111222333444']],
            'data'     => ['name' => 'registro'],
        ];

        $response = $this->postJson('/api/discord/interactions', $payload);

        $response->assertOk();

        $content = $response->json('data.content') ?? '';
        $this->assertStringNotContainsString('registrada', $content);
    }
}
