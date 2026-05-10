<?php

namespace Tests\Unit\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\LaravelQaLoopRunner;
use App\Support\ConfiguredAgentPromptRegistry;
use App\Support\UserAiSettingsResolver;
use App\Domain\Scene\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class LaravelQaLoopRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rewrites_when_qa_returns_major_issue(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function embeddings(string $model, string $text): array { return []; }

            public int $calls = 0;

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return [
                        'text' => json_encode([
                            'status' => 'needs_revision',
                            'issues' => [
                                [
                                    'severity' => 'major',
                                    'code' => 'player_rewrite',
                                    'message' => 'Reescribe la accion del jugador.',
                                    'instruction' => 'No reescribas la accion del jugador; continua con consecuencias de NPC y entorno.',
                                ],
                            ],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                if ($this->calls === 2) {
                    return [
                        'text' => 'El guardia se quedo inmovil un segundo, y luego cerro la mano sobre la lanza.',
                    ];
                }

                return [
                    'text' => json_encode([
                        'status' => 'approved',
                        'issues' => [],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        };

        $entries = [];
        $runner = new LaravelQaLoopRunner(
            aiChatGateway: $gateway,
            userAiSettingsResolver: new UserAiSettingsResolver(),
            promptRegistry: new ConfiguredAgentPromptRegistry(),
            logger: new ArrayStructuredLogger($entries),
        );

        $scene = Activity::fromArray([
            'id' => 'scene_demo',
            'vaultId' => 'vault_demo',
            'title' => 'Demo',
            'draft' => 'Borrador previo',
        ]);

        $result = $runner->run(
            scene: $scene,
            context: ['characters' => [], 'recent_messages' => []],
            userMessage: '**Lo miro fijamente** "Habla."',
            mode: 'write_scene',
            outputMd: 'Tomas al guardia por el cuello y le ordenas hablar.',
            qaLoop: ['enabled' => true, 'max_passes' => 3, 'min_severity' => 'medium'],
            userId: null,
        );

        $this->assertTrue($result['enabled']);
        $this->assertTrue($result['triggered']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('major', $result['highestSeverity']);
        $this->assertSame('El guardia se quedo inmovil un segundo, y luego cerro la mano sobre la lanza.', $result['outputMd']);
        $this->assertSame(2, $result['passes']);
        $messages = array_column($entries, 'message');
        $this->assertContains('QA loop payload preparado', $messages);
        $this->assertContains('QA loop review parseado', $messages);
        $this->assertContains('QA loop rewrite payload preparado', $messages);
        $this->assertContains('QA loop reescritura recibida', $messages);
        $this->assertContains('QA loop finalizado', $messages);
    }

    public function test_it_skips_rewrite_when_issue_is_below_threshold(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function embeddings(string $model, string $text): array { return []; }

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => json_encode([
                        'status' => 'needs_revision',
                        'issues' => [
                            [
                                'severity' => 'minor',
                                'code' => 'style_density',
                                'message' => 'La prosa esta un poco cargada.',
                                'instruction' => 'Reduce adjetivos repetidos.',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        };

        $runner = new LaravelQaLoopRunner(
            aiChatGateway: $gateway,
            userAiSettingsResolver: new UserAiSettingsResolver(),
            promptRegistry: new ConfiguredAgentPromptRegistry(),
            logger: new ArrayStructuredLogger(),
        );

        $scene = Activity::fromArray([
            'id' => 'scene_demo',
            'vaultId' => 'vault_demo',
            'title' => 'Demo',
            'draft' => 'Borrador previo',
        ]);

        $result = $runner->run(
            scene: $scene,
            context: ['characters' => [], 'recent_messages' => []],
            userMessage: 'Espero en silencio.',
            mode: 'write_scene',
            outputMd: 'El salon guardo silencio mientras el humo temblaba sobre las velas.',
            qaLoop: ['enabled' => true, 'max_passes' => 3, 'min_severity' => 'major'],
            userId: null,
        );

        $this->assertSame('below_threshold', $result['status']);
        $this->assertFalse($result['triggered']);
        $this->assertSame('El salon guardo silencio mientras el humo temblaba sobre las velas.', $result['outputMd']);
    }
}
