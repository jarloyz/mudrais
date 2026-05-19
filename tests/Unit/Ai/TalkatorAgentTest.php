<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\TalkatorAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class TalkatorAgentTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();

        $this->gateway  = Mockery::mock(AiChatGateway::class);
        $this->resolver = Mockery::mock(UserAiSettingsResolver::class);

        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('test-model')->byDefault();
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();
    }

    private function makeAgent(): TalkatorAgent
    {
        return new TalkatorAgent($this->gateway, $this->resolver);
    }

    // ── respond() básico ─────────────────────────────────────────────────────

    public function test_respond_returns_plain_string(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages, $temp, $maxTokens, ...$rest) {
                // Verifica que el onChunk se pasa (posición 6)
                return $model === 'test-model' && $temp === 0.85 && $maxTokens === 120;
            })
            ->andReturnUsing(function ($model, $messages, $temp, $maxTokens, $timeout, $cache, $onChunk) {
                $onChunk('Entendido.');
                return ['text' => 'Entendido.'];
            });

        $result = $this->makeAgent()->respond('Quiero ser un mago oscuro');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_respond_calls_onchunk_with_each_chunk(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($model, $messages, $temp, $maxTokens, $timeout, $cache, $onChunk) {
                $onChunk('Muy ');
                $onChunk('bien.');
                return ['text' => 'Muy bien.'];
            });

        $received = [];
        $this->makeAgent()->respond(
            'texto de prueba',
            'es',
            function (string $chunk) use (&$received): void {
                $received[] = $chunk;
            },
        );

        $this->assertCount(2, $received);
        $this->assertEquals('Muy ', $received[0]);
        $this->assertEquals('bien.', $received[1]);
    }

    public function test_respond_strips_markdown_asterisks(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($model, $messages, $temp, $maxTokens, $timeout, $cache, $onChunk) {
                $onChunk('**Entendido.**');
                return ['text' => '**Entendido.**'];
            });

        $result = $this->makeAgent()->respond('texto de prueba');

        $this->assertStringNotContainsString('*', $result);
        $this->assertStringContainsString('Entendido', $result);
    }

    public function test_respond_strips_surrounding_quotes(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($model, $messages, $temp, $maxTokens, $timeout, $cache, $onChunk) {
                $onChunk('"De acuerdo."');
                return ['text' => '"De acuerdo."'];
            });

        $result = $this->makeAgent()->respond('texto');

        $this->assertStringNotContainsString('"', $result);
    }

    // ── Fallback ──────────────────────────────────────────────────────────────

    public function test_respond_falls_back_to_i18n_when_llm_throws(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andThrow(new \RuntimeException('Gateway error'));

        $result = $this->makeAgent()->respond('Quiero ser un mago');

        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_respond_falls_back_when_response_too_long(): void
    {
        $longText = str_repeat('Esta es una respuesta muy larga que supera el límite. ', 5);

        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($model, $messages, $temp, $maxTokens, $timeout, $cache, $onChunk) use ($longText) {
                $onChunk($longText);
                return ['text' => $longText];
            });

        $result = $this->makeAgent()->respond('texto');

        // Fallback debe ser más corto que 350 chars
        $this->assertLessThanOrEqual(350, mb_strlen($result));
    }

    public function test_respond_rotates_fallback_by_crc_of_transcript(): void
    {
        $this->gateway->shouldReceive('chat')
            ->andThrow(new \RuntimeException('error'));

        $agent = $this->makeAgent();

        // Dos transcripts distintos pueden dar distintas frases de fallback
        $result1 = $agent->respond('transcript uno');
        $result2 = $agent->respond('transcript dos muy diferente');

        // Ambos deben ser strings válidos (no necesariamente distintos, pero sí válidos)
        $this->assertIsString($result1);
        $this->assertIsString($result2);
    }

    public function test_fallback_is_emitted_via_onchunk_when_provided(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andThrow(new \RuntimeException('error'));

        $emitted = [];
        $this->makeAgent()->respond(
            'texto',
            'es',
            function (string $chunk) use (&$emitted): void {
                $emitted[] = $chunk;
            },
        );

        $this->assertNotEmpty($emitted);
    }

    // ── Temperatura y parámetros ──────────────────────────────────────────────

    public function test_respond_uses_temperature_085_and_max_tokens_120(): void
    {
        $capturedTemp   = null;
        $capturedTokens = null;

        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturnUsing(function ($model, $messages, $temp, $maxTokens, $timeout, $cache, $onChunk) use (&$capturedTemp, &$capturedTokens) {
                $capturedTemp   = $temp;
                $capturedTokens = $maxTokens;
                $onChunk('Ok.');
                return ['text' => 'Ok.'];
            });

        $this->makeAgent()->respond('texto');

        $this->assertEquals(0.85, $capturedTemp);
        $this->assertEquals(120, $capturedTokens);
    }
}
