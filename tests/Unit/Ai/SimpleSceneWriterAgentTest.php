<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Agents\SimpleSceneWriterAgent;
use App\Models\Player;
use App\Models\AgentConfig;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class SimpleSceneWriterAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_uses_cache_control_for_anthropic_models_inside_openrouter(): void
    {
        config()->set('historia.ai.cache_control', ['type' => 'ephemeral']);

        $gateway = new class implements AiChatGateway
        {
            public ?array $lastCacheControl = null;
            public array $lastOptions = [];

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->lastCacheControl = $cacheControl;
                $this->lastOptions = $options;

                return [
                    'text' => 'Texto generado por writer.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => null,
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'provider'     => 'openrouter',
            'writer_model' => 'anthropic/claude-sonnet-4.5',
        ]);

        $agent = new SimpleSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_writer_cache',
            'vaultId' => 'vault_test',
            'title' => 'Escena cache',
            'objective' => 'Probar cache',
            'draft' => 'Borrador',
        ]);

        $agent->generate($scene, [], 'Continua', 'write_scene', null, $player->id);

        $this->assertSame(['type' => 'ephemeral'], $gateway->lastCacheControl);
        $this->assertSame('scene:scene_writer_cache|mode:write_scene|user:'.$player->id, $gateway->lastOptions['session_id']);
    }

    public function test_writer_skips_cache_control_for_non_anthropic_models(): void
    {
        config()->set('historia.ai.cache_control', ['type' => 'ephemeral']);

        $gateway = new class implements AiChatGateway
        {
            public ?array $lastCacheControl = ['unexpected' => true];
            public array $lastOptions = [];

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->lastCacheControl = $cacheControl;
                $this->lastOptions = $options;

                return [
                    'text' => 'Texto generado por writer.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => null,
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'provider'     => 'openrouter',
            'writer_model' => 'x-ai/grok-4.1-fast',
            'settings_json' => [
                'parameters' => [
                    'writer' => [
                        'style_profile' => 'sensorial',
                        'style_notes' => 'Más humedad, respiración contenida y tensión táctil.',
                        'response_length' => 'corto',
                    ],
                ],
            ],
        ]);

        $agent = new SimpleSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_writer_no_cache',
            'vaultId' => 'vault_test',
            'title' => 'Escena sin cache',
            'objective' => 'Probar sin cache',
            'draft' => 'Borrador',
        ]);

        $agent->generate($scene, [], 'Continua', 'write_scene', null, $player->id);

        $this->assertNull($gateway->lastCacheControl);
        $this->assertSame('scene:scene_writer_no_cache|mode:write_scene|user:'.$player->id, $gateway->lastOptions['session_id']);
    }

    public function test_writer_uses_separated_prompt_with_roleplay_rules(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public array $lastMessages = [];

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->lastMessages = $messages;

                return [
                    'text' => 'Texto generado por writer.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => null,
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'provider'     => 'openrouter',
            'writer_model' => 'x-ai/grok-4.1-fast',
            'settings_json' => [
                'parameters' => [
                    'writer' => [
                        'style_profile' => 'sensorial',
                        'style_notes' => 'Más humedad, respiración contenida y tensión táctil.',
                        'response_length' => 'corto',
                    ],
                ],
            ],
        ]);

        $agent = new SimpleSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_writer_prompt',
            'vaultId' => 'vault_test',
            'title' => 'Escena prompt',
            'objective' => 'Probar prompt separado',
            'draft' => 'Inicio de escena.',
        ]);

        $agent->generate($scene, [
            'historySummary' => 'Ana desconfia del visitante.',
            'quests' => [
                [
                    'quest_id' => 'quest_escape',
                    'title' => 'Fuga del refugio',
                    'status' => 'active',
                    'current_stage_number' => 20,
                    'current_step' => [
                        'description' => 'Neutraliza al guardia de la puerta',
                    ],
                    'ai_summary' => 'La salida sigue bloqueada.',
                ],
            ],
            'recentMessages' => [
                ['role' => 'user', 'content' => 'Miro la puerta.'],
                ['role' => 'assistant', 'content' => 'La casa cruje.'],
            ],
        ], 'Abro lentamente.', 'write_scene', null, $player->id);

        $this->assertCount(3, $gateway->lastMessages);
        $this->assertStringContainsString('narrador reactivo del mundo', $gateway->lastMessages[0]['content']);
        $this->assertStringContainsString('Puede venir en primera, segunda o tercera persona', $gateway->lastMessages[0]['content']);
        $this->assertStringContainsString('las acciones vienen entre **asteriscos**', $gateway->lastMessages[0]['content']);
        $this->assertStringContainsString('No reformules ni reescribas el input del jugador', $gateway->lastMessages[1]['content']);
        $this->assertStringContainsString('puede llegar en primera, segunda o tercera persona', $gateway->lastMessages[1]['content']);
        $this->assertStringContainsString('Prosa sensorial', $gateway->lastMessages[2]['content']);
        $this->assertStringContainsString('Más humedad, respiración contenida y tensión táctil.', $gateway->lastMessages[2]['content']);
        $this->assertStringContainsString('Respuesta breve: 1 a 3 parrafos cortos', $gateway->lastMessages[2]['content']);
        $this->assertStringContainsString('## Memoria resumida', $gateway->lastMessages[2]['content']);
        $this->assertStringContainsString('## Quests activas y estado actual', $gateway->lastMessages[2]['content']);
        $this->assertStringContainsString('Neutraliza al guardia de la puerta', $gateway->lastMessages[2]['content']);
        $this->assertStringContainsString('Abro lentamente.', $gateway->lastMessages[2]['content']);
        $this->assertStringNotContainsString('Markdown', $gateway->lastMessages[0]['content']);
    }

    public function test_agent_parses_tool_calls_into_state_changes_and_notes(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => 'Ana se siente cansada tras la carrera.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_character_status',
                                'arguments' => json_encode([
                                    'changes' => [
                                        [
                                            'character_name' => 'Ana',
                                            'stamina' => 40,
                                            'status_tags' => ['cansada'],
                                            'mood' => 'exhausta'
                                        ]
                                    ]
                                ])
                            ]
                        ],
                        [
                            'id' => 'call_2',
                            'type' => 'function',
                            'function' => [
                                'name' => 'emit_narrative_notes',
                                'arguments' => json_encode([
                                    'notes' => ['Ana ha perdido su mochila durante la huida.']
                                ])
                            ]
                        ]
                    ]
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $agent = new SimpleSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_tools',
            'vaultId' => 'vault_test',
            'title' => 'Huida',
            'objective' => 'Escapar',
            'draft' => 'Inicio de huida',
        ]);

        $result = $agent->generate($scene, [], 'Corro por el bosque', 'write_scene');

        $this->assertCount(1, $result['stateChanges']);
        $this->assertSame('Ana', $result['stateChanges'][0]['character_name']);
        $this->assertSame(40, $result['stateChanges'][0]['stamina']);

        $this->assertCount(1, $result['notes']);
        $this->assertSame('Ana ha perdido su mochila durante la huida.', $result['notes'][0]);
    }
}
