<?php

namespace Tests\Feature\Api;

use App\Jobs\Discord\ProcessBuscarJob;
use App\Jobs\Discord\ProcessFichaModalJob;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use App\Jobs\Discord\ProcessStatusJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscordCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // Intercepta todos los jobs — no se ejecutan realmente

        // Mock de validación de firma para que los tests pasen sin cabeceras reales
        $mock = $this->createMock(\App\Services\Discord\Contracts\DiscordSignatureValidator::class);
        $mock->method('isValid')->willReturn(true);
        $this->app->instance(\App\Services\Discord\Contracts\DiscordSignatureValidator::class, $mock);
    }

    // --- Helpers ---

    private function discordPost(array $interaction): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/discord/interactions', $interaction);
    }

    private function discordUser(array $overrides = []): array
    {
        return array_merge(['id' => '111222333444', 'username' => 'testplayer'], $overrides);
    }

    private function slashCommand(string $name, array $options = []): array
    {
        return [
            'type'   => 2,
            'token'  => 'test_token_' . $name,
            'member' => ['user' => $this->discordUser()],
            'data'   => array_merge(['name' => $name], $options ? ['options' => $options] : []),
        ];
    }

    // --- /status → ACK type:5 + ProcessStatusJob ---

    public function test_status_returns_deferred_ack_and_dispatches_job(): void
    {
        $response = $this->discordPost($this->slashCommand('status'));

        $response->assertOk()->assertJson(['type' => 5]);

        Queue::assertPushed(ProcessStatusJob::class, function ($job) {
            return $job->token     === 'test_token_status'
                && $job->discordId === '111222333444';
        });
    }

    // --- /registro → Embed introductorio con 3 botones de género (nuevo jugador), sin job ---

    public function test_registro_responds_with_step1_modal(): void
    {
        // Jugador nuevo (no existe en BD) → embed de bienvenida con 3 botones de género
        $response = $this->discordPost($this->slashCommand('registro'));

        $response->assertOk()
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64);

        $this->assertStringContainsString('Bienvenido', $response->json('data.embeds.0.title'));

        // Los 3 botones de género deben estar presentes
        $customIds = array_column($response->json('data.components.0.components'), 'custom_id');
        $this->assertContains('btn_reg_hombre', $customIds);
        $this->assertContains('btn_reg_mujer', $customIds);
        $this->assertContains('btn_reg_otro', $customIds);

        Queue::assertNothingPushed();
    }

    // --- Modal step 1 submit válido → type:9 step 2 + Player actualizado ---

    public function test_registro_step1_valid_saves_player_and_shows_step2(): void
    {
        $interaction = [
            'type'   => 5,
            'token'  => 'test_token_step1',
            'member' => ['user' => $this->discordUser()],
            'data'   => [
                'custom_id'  => 'mudrais_registro_step_1',
                'components' => [
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'edad',        'value'  => '28']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'nacionalidad', 'value'  => 'México']]],
                    ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'experiencia',  'values' => ['3']]]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'horarios',     'value'  => 'L-V noches']]],
                    ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'extension',    'values' => ['4']]]],
                ],
            ],
        ];

        $response = $this->discordPost($interaction);

        // Step1 usa type:7 (UPDATE_MESSAGE) — muta el embed original, sin mensaje nuevo
        $response->assertOk()
            ->assertJsonPath('type', 7)
            ->assertJsonPath('data.components.0.components.0.custom_id', 'btn_abrir_modal_2');

        Queue::assertPushed(\App\Jobs\Discord\ProcessRegistroStep1Job::class);
    }

    // --- Modal step 1 submit inválido → type:9 step 1 re-abre ---

    public function test_registro_step1_invalid_edad_reopens_step1(): void
    {
        $interaction = [
            'type'   => 5,
            'token'  => 'test_token_step1_bad',
            'member' => ['user' => $this->discordUser()],
            'data'   => [
                'custom_id'  => 'mudrais_registro_step_1',
                'components' => [
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'edad',        'value'  => 'abc']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'nacionalidad', 'value'  => 'México']]],
                    ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'experiencia',  'values' => ['3']]]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'horarios',     'value'  => 'L-V noches']]],
                    ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'extension',    'values' => ['4']]]],
                ],
            ],
        ];

        $response = $this->discordPost($interaction);

        // Step1 inválido usa type:7 — muta el embed original con el error y botón retry
        $response->assertOk()
            ->assertJsonPath('type', 7)
            ->assertJsonPath('data.components.0.components.0.custom_id', 'btn_retry_modal_1');

        $this->assertStringContainsString('Error', $response->json('data.embeds.0.title'));

        Queue::assertNothingPushed();
    }

    // --- Modal step 2 submit → type:6 + ProcessRegistroStep2Job ---

    public function test_registro_step2_dispatches_job(): void
    {
        $interaction = [
            'type'   => 5,
            'token'  => 'test_token_step2',
            'member' => ['user' => $this->discordUser()],
            'data'   => [
                'custom_id'  => 'mudrais_registro_step_2:0',
                'components' => [
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'red_lines',      'value' => 'gore']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'yellow_lines',  'value' => '']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'preferences',     'value' => 'Fantasía, Terror']]],
                    ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'style',           'value' => '3ra persona, drama lento']]],
                ],
            ],
        ];

        $response = $this->discordPost($interaction);

        // type:5 — Deferred ephemeral
        $response->assertOk()->assertJsonPath('type', 5);

        Queue::assertPushed(ProcessRegistroStep2Job::class, function ($job) {
            return $job->token                      === 'test_token_step2'
                && $job->discordId                  === '111222333444'
                && ($job->data['preferences'] ?? null) === 'Fantasía, Terror';
        });
    }

    // --- /ficha → Inmediato type:9 (modal), sin job ---

    public function test_ficha_responds_with_modal_immediately(): void
    {
        $response = $this->discordPost($this->slashCommand('ficha'));

        $response->assertOk()
            ->assertJsonPath('type', 9)
            ->assertJsonPath('data.custom_id', 'mudrais_ficha')
            ->assertJsonPath('data.components.0.components.0.custom_id', 'profile_text')
            ->assertJsonPath('data.components.0.components.0.style', 2);

        Queue::assertNothingPushed(); // No job — respuesta inmediata obligatoria
    }

    // --- Modal submit → ACK type:6 + ProcessFichaModalJob ---

    public function test_ficha_modal_submit_returns_deferred_ack_and_dispatches_job(): void
    {
        $profileText = "Edad: 28\nNacionalidad: México\nExperiencia: Veterano\nEstilo: Narrativa oscura.";

        $interaction = [
            'type'   => 5,
            'token'  => 'test_token_modal',
            'member' => ['user' => $this->discordUser()],
            'data'   => [
                'custom_id'  => 'mudrais_ficha',
                'components' => [
                    [
                        'type'       => 1,
                        'components' => [
                            ['type' => 4, 'custom_id' => 'profile_text', 'value' => $profileText],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->discordPost($interaction);

        $response->assertOk()->assertExactJson(['type' => 6]);

        Queue::assertPushed(ProcessFichaModalJob::class, function ($job) use ($profileText) {
            return $job->token       === 'test_token_modal'
                && $job->profileText === $profileText
                && $job->discordId   === '111222333444';
        });
    }

    // --- /buscar-partida → ACK type:5 + ProcessBuscarJob ---

    public function test_buscar_partida_returns_deferred_ack_and_dispatches_job(): void
    {
        $interaction = $this->slashCommand('buscar-partner');
        $interaction['token'] = 'test_token_buscar';

        $response = $this->discordPost($interaction);

        $response->assertOk()->assertJson(['type' => 5]);

        Queue::assertPushed(ProcessBuscarJob::class, function ($job) {
            return $job->token     === 'test_token_buscar'
                && $job->discordId === '111222333444';
        });
    }
}
