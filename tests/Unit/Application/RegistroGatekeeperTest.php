<?php

namespace Tests\Unit\Application;

use App\Domains\Matchmaking\Models\Archetype;
use App\Http\Controllers\Api\DiscordController;
use App\Models\GameItem;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Verifica las respuestas del gatekeeper de /registro según el estado del jugador.
 */
class RegistroGatekeeperTest extends TestCase
{
    use RefreshDatabase;

    private function makeRegistroPayload(string $discordId = '123456789'): array
    {
        return [
            'type'     => 2,
            'token'    => 'test-token',
            'guild_id' => 'guild-test',
            'data'     => ['name' => 'register'],
            'member'   => ['user' => ['id' => $discordId, 'username' => 'tester']],
        ];
    }

    private function callHandle(array $payload): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/discord/interactions', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');
        return app(DiscordController::class)->handle($request);
    }

    /** Crea un perfil de arquetipo para el jugador para que el flow pase el gate de arquetipo. */
    private function seedArchetypeProfile(Player $player): void
    {
        $archetype = Archetype::firstOrCreate(
            ['qdrant_vector_name' => 'ttrpg_text_v1'],
            ['name' => 'TTRPG Texto'],
        );
        PlayerArchetypeProfile::firstOrCreate(
            ['player_id' => $player->id, 'archetype_id' => $archetype->id],
            ['discord_user_id' => $player->discord_id, 'positive_prefs' => []],
        );
    }

    public function test_nuevo_jugador_recibe_embed_verde(): void
    {
        GameItem::create([
            'key'          => 'registro_edit',
            'name'         => 'Edición de Perfil',
            'type'         => 'action_cost',
            'coin_delta'   => -50,
            'energy_delta' => 0,
            'is_active'    => true,
        ]);

        $response = $this->callHandle($this->makeRegistroPayload('nuevo-user-id'));
        $json     = $response->getData(true);

        $this->assertSame(4, $json['type']);
        $this->assertStringContainsString('MUDRAIS', $json['data']['embeds'][0]['title']);

        $customIds = array_column($json['data']['components'][0]['components'], 'custom_id');
        $this->assertContains('btn_reg_hombre', $customIds);
        $this->assertContains('btn_reg_mujer', $customIds);
        $this->assertContains('btn_reg_otro', $customIds);
    }

    public function test_jugador_sin_tutorial_recibe_error(): void
    {
        $player = Player::factory()->create([
            'discord_id'         => 'jugador-sin-tutorial',
            'tutorial_completed' => false,
            'coin'               => 100,
        ]);
        $this->seedArchetypeProfile($player);

        $response = $this->callHandle($this->makeRegistroPayload('jugador-sin-tutorial'));
        $json     = $response->getData(true);

        $this->assertSame(4, $json['type']);
        $this->assertSame(64, $json['data']['flags']);
        $this->assertStringContainsString('Tutorial', $json['data']['content']);
    }

    public function test_jugador_sin_monedas_recibe_error(): void
    {
        $player = Player::factory()->create([
            'discord_id'         => 'jugador-pobre',
            'tutorial_completed' => true,
            'coin'               => 10,
        ]);
        $this->seedArchetypeProfile($player);
        GameItem::create([
            'key'          => 'registro_edit',
            'name'         => 'Edición de Perfil',
            'type'         => 'action_cost',
            'coin_delta'   => -50,
            'energy_delta' => 0,
            'is_active'    => true,
        ]);

        $response = $this->callHandle($this->makeRegistroPayload('jugador-pobre'));
        $json     = $response->getData(true);

        $this->assertSame(4, $json['type']);
        $this->assertSame(64, $json['data']['flags']);
        $this->assertStringContainsString('50', $json['data']['content']);  // costo en monedas
    }

    public function test_jugador_habilitado_recibe_embed_azul(): void
    {
        $player = Player::factory()->create([
            'discord_id'         => 'jugador-rico',
            'tutorial_completed' => true,
            'coin'               => 100,
        ]);
        $this->seedArchetypeProfile($player);
        GameItem::create([
            'key'          => 'registro_edit',
            'name'         => 'Edición de Perfil',
            'type'         => 'action_cost',
            'coin_delta'   => -50,
            'energy_delta' => 0,
            'is_active'    => true,
        ]);

        $response = $this->callHandle($this->makeRegistroPayload('jugador-rico'));
        $json     = $response->getData(true);

        $this->assertSame(4, $json['type']);
        $this->assertStringContainsString('MUDRAIS', $json['data']['embeds'][0]['title']);
        $this->assertSame('btn_abrir_modal_1_edicion', $json['data']['components'][0]['components'][0]['custom_id']);
    }
}
