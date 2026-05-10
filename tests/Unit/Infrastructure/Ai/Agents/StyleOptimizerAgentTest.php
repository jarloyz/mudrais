<?php

namespace Tests\Unit\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class StyleOptimizerAgentTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();
    }

    private function makeGateway(array $responses): AiChatGateway
    {
        return new class ($responses) implements AiChatGateway {
            private int $call = 0;

            public function __construct(private array $responses) {}

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                return ['text' => $this->responses[$this->call++] ?? ''];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };
    }

    private function makeResolver(string $model = 'test-model'): UserAiSettingsResolver
    {
        $resolver = $this->createMock(UserAiSettingsResolver::class);
        $resolver->method('resolveAgentModel')->willReturn($model);
        return $resolver;
    }

    private function structuredFacts(array $positives = [], array $redLines = [], array $yellowLines = []): string
    {
        return json_encode([
            'positives'    => $positives,
            'red_lines'    => $redLines,
            'yellow_lines' => $yellowLines,
        ]);
    }

    public function test_two_step_pipeline_returns_optimized_paragraph(): void
    {
        $paragraph = 'First-person perspective. Slow-burn pacing.';

        $gateway = $this->makeGateway([
            $this->structuredFacts(['First-person perspective', 'Slow pacing'], ['No magic']),
            $paragraph,
        ]);

        $agent = new StyleOptimizerAgent($gateway, $this->makeResolver());

        $result = $agent->optimize('primera persona, cero magia, ritmo lento', 1);

        $this->assertSame($paragraph, $result);
    }

    public function test_throws_when_gatekeeper_returns_invalid_json(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gatekeeper no pudo extraer facts del perfil.');

        $gateway = $this->makeGateway(['esto no es json válido']);

        (new StyleOptimizerAgent($gateway, $this->makeResolver()))
            ->optimize('primera persona, cero magia', 1);
    }

    public function test_throws_when_gatekeeper_returns_empty_positives(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gatekeeper no pudo extraer facts del perfil.');

        // Valid structured JSON but positives is empty (e.g. input with no style content)
        $gateway = $this->makeGateway([
            $this->structuredFacts([], ['No violence']),
        ]);

        (new StyleOptimizerAgent($gateway, $this->makeResolver()))
            ->optimize('hola!!', 1);
    }

    public function test_throws_when_optimizer_returns_empty_string(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Optimizer devolvió respuesta vacía.');

        $gateway = $this->makeGateway([
            $this->structuredFacts(['First-person perspective']),
            '',
        ]);

        (new StyleOptimizerAgent($gateway, $this->makeResolver()))
            ->optimize('primera persona', 1);
    }

    public function test_returns_empty_string_unchanged_without_calling_gateway(): void
    {
        $gateway = $this->makeGateway([]);

        $agent = new StyleOptimizerAgent($gateway, $this->makeResolver());

        $result = $agent->optimize('   ', null);

        $this->assertSame('', $result);
    }

    public function test_strips_markdown_fences_from_gatekeeper_json(): void
    {
        $paragraph = 'Slow-burn pacing with high dramatic tension.';

        $gateway = $this->makeGateway([
            "```json\n" . $this->structuredFacts(['Slow pacing']) . "\n```",
            $paragraph,
        ]);

        $agent = new StyleOptimizerAgent($gateway, $this->makeResolver());

        $result = $agent->optimize('ritmo lento', 1);

        $this->assertSame($paragraph, $result);
    }

    public function test_throws_when_gatekeeper_model_not_configured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modelo gatekeeper no configurado.');

        $resolver = $this->createMock(UserAiSettingsResolver::class);
        $resolver->method('resolveAgentModel')->willReturn('');

        (new StyleOptimizerAgent($this->makeGateway([]), $resolver))
            ->optimize('primera persona', 1);
    }

    public function test_throws_when_optimizer_model_not_configured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modelo optimizer no configurado.');

        $resolver = $this->createMock(UserAiSettingsResolver::class);
        $resolver->method('resolveAgentModel')
            ->willReturnCallback(fn ($playerId, $agent) => $agent === 'gatekeeper' ? 'gatekeeper-model' : '');

        $gateway = $this->makeGateway([
            $this->structuredFacts(['First-person perspective']),
        ]);

        (new StyleOptimizerAgent($gateway, $resolver))
            ->optimize('primera persona', 1);
    }
}
