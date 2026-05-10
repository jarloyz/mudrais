<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Agents\AiQuestAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class AiQuestAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_directive_when_no_active_quests_exist(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public int $calls = 0;

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->calls++;

                return ['text' => '{}', 'usage' => null, 'raw' => null, 'tool_calls' => null];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $agent = new AiQuestAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_quest',
            'vaultId' => 'vault_test',
            'title' => 'Escena quest',
            'draft' => 'Inicio',
        ]);

        $result = $agent->evaluate($scene, ['quests' => []], 'Disparo al guardia.');

        $this->assertFalse($result['matched']);
        $this->assertSame(0, $gateway->calls);
    }

    public function test_normalizes_valid_json_response_into_writer_directive(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => '{"matched":true,"quest_id":"quest_escape","advance_step":true,"new_stage_number":30,"new_status":"active","ai_summary":"El guardia cae y la salida queda libre.","directive_for_writer":"Narra la victoria inmediata y el acceso despejado.","confidence":0.91}',
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

        $agent = new AiQuestAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $scene = Activity::fromArray([
            'id' => 'scene_quest',
            'vaultId' => 'vault_test',
            'title' => 'Escena quest',
            'draft' => 'Inicio',
        ]);

        $result = $agent->evaluate($scene, [
            'quests' => [
                [
                    'quest_id' => 'quest_escape',
                    'title' => 'Fuga del refugio',
                ],
            ],
        ], 'Disparo al guardia.');

        $this->assertTrue($result['matched']);
        $this->assertSame('quest_escape', $result['quest_id']);
        $this->assertSame(30, $result['new_stage_number']);
        $this->assertSame('Narra la victoria inmediata y el acceso despejado.', $result['directive_for_writer']);
    }
}
