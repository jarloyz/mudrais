<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\AiQuestScaffolderAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class AiQuestScaffolderAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_valid_scaffold_response(): void
    {
        $gateway = new class implements AiChatGateway
        {
            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return [
                    'text' => '{"title":"Fuga del refugio","description":"Escapa sin activar a la faccion.","type":"main","status":"active","steps":[{"stage_number":10,"description":"Evalua la salida principal.","is_optional":false},{"stage_number":20,"description":"Neutraliza al guardia.","is_optional":false},{"stage_number":30,"description":"Cruza el umbral.","is_optional":false}]}',
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

        $agent = new AiQuestScaffolderAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger());
        $result = $agent->generate('Escapar del refugio sin alertar a la faccion.');

        $this->assertSame('Fuga del refugio', $result['title']);
        $this->assertCount(3, $result['steps']);
        $this->assertSame(20, $result['steps'][1]['stage_number']);
    }
}
