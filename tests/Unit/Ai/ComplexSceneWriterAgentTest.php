<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Agents\ComplexSceneWriterAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class ComplexSceneWriterAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_uses_tool_calls_for_mutations(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public array $lastOptions = [];

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->lastOptions = $options;

                return [
                    'text' => 'La oscuridad te rodea.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => [
                        [
                            'id' => 'tc_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_character_status',
                                'arguments' => json_encode(['changes' => [['character_name' => 'Ana', 'health' => 85]]])
                            ]
                        ],
                        [
                            'id' => 'tc_2',
                            'type' => 'function',
                            'function' => [
                                'name' => 'emit_narrative_notes',
                                'arguments' => json_encode(['notes' => ['Se escuchan ruidos extraños.']])
                            ]
                        ]
                    ],
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $agent = new ComplexSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'complex_scene',
            'vaultId' => 'vault_test',
            'title' => 'Escena compleja',
            'draft' => 'Borrador',
        ]);

        $result = $agent->generate($scene, [], 'Exploro', 'write_scene');

        $this->assertSame('La oscuridad te rodea.', $result['outputMd']);
        $this->assertCount(1, $result['stateChanges']);
        $this->assertSame('Ana', $result['stateChanges'][0]['character_name']);
        $this->assertSame(85, $result['stateChanges'][0]['health']);
        $this->assertCount(1, $result['notes']);
        $this->assertSame('Se escuchan ruidos extraños.', $result['notes'][0]);
        $this->assertArrayNotHasKey('response_format', $gateway->lastOptions);
    }

    public function test_agent_handles_raw_text_without_mutations(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => 'Esto es solo texto narrativo.',
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

        $agent = new ComplexSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'complex_scene',
            'vaultId' => 'vault_test',
            'title' => 'Escena compleja',
            'draft' => 'Borrador',
        ]);

        $result = $agent->generate($scene, [], 'msg', 'write_scene');

        $this->assertSame('Esto es solo texto narrativo.', $result['outputMd']);
        $this->assertEmpty($result['stateChanges']);
        $this->assertEmpty($result['notes']);
    }
}
