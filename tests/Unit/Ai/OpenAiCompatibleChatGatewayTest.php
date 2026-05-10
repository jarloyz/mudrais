<?php

namespace Tests\Unit\Ai;

use App\Infrastructure\Ai\OpenAiCompatibleChatGateway;
use App\Models\ProviderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class OpenAiCompatibleChatGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function makeGateway(): OpenAiCompatibleChatGateway
    {
        return new OpenAiCompatibleChatGateway(
            baseUrl: 'http://test-server/v1',
            apiKey: 'test-key',
            presetName: 'test_preset',
            logger: new ArrayStructuredLogger(),
        );
    }

    public function test_non_streaming_success_writes_provider_log(): void
    {
        Http::fake([
            'http://test-server/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hola mundo']]],
                'usage'   => ['total_tokens' => 42],
            ], 200),
        ]);

        $result = $this->makeGateway()->chat(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'ping']],
            temperature: 0.7,
            maxOutputTokens: 100,
            options: ['agent' => 'test_agent'],
        );

        $this->assertSame('Hola mundo', $result['text']);
        $this->assertDatabaseHas('provider_logs', [
            'agent'        => 'test_agent',
            'model'        => 'test-model',
            'total_tokens' => 42,
            'status'       => 'success',
        ]);
    }

    public function test_non_streaming_error_writes_provider_log_with_error_status(): void
    {
        Http::fake([
            'http://test-server/v1/chat/completions' => Http::response('Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->makeGateway()->chat(
                model: 'test-model',
                messages: [['role' => 'user', 'content' => 'ping']],
                temperature: 0.7,
                maxOutputTokens: 100,
                options: ['agent' => 'failing_agent'],
            );
        } finally {
            $this->assertDatabaseHas('provider_logs', [
                'agent'        => 'failing_agent',
                'model'        => 'test-model',
                'total_tokens' => null,
                'status'       => 'error',
            ]);
        }
    }

    public function test_missing_agent_option_logs_unknown(): void
    {
        Http::fake([
            'http://test-server/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
                'usage'   => ['total_tokens' => 5],
            ], 200),
        ]);

        $this->makeGateway()->chat(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'ping']],
            temperature: 0.7,
            maxOutputTokens: 100,
        );

        $this->assertDatabaseHas('provider_logs', [
            'agent'  => 'unknown',
            'status' => 'success',
        ]);
    }

    public function test_non_streaming_logs_latency_ms(): void
    {
        Http::fake([
            'http://test-server/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
                'usage'   => ['total_tokens' => 10],
            ], 200),
        ]);

        $this->makeGateway()->chat(
            model: 'latency-test-model',
            messages: [['role' => 'user', 'content' => 'ping']],
            temperature: 0.7,
            maxOutputTokens: 100,
        );

        $log = ProviderLog::where('model', 'latency-test-model')->first();
        $this->assertNotNull($log);
        $this->assertGreaterThan(0, $log->latency_ms);
    }
}
