<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Contracts\StructuredLogger;
use App\Models\ProviderLog;
use App\Support\LogPreview;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterEmbeddingGateway implements EmbeddingGateway
{
    public function __construct(
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<int, float>
     */
    public function embed(string $model, string $text): array
    {
        $apiKey = trim((string) config('historia.ai.openrouter.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Falta OPENROUTER_API_KEY');
        }

        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'component' => 'openrouter_embedding_gateway',
            'provider' => 'openrouter',
            'model' => $model,
        ]);

        $logger->debug('OpenRouter embedding request prepared', [
            'text_preview' => LogPreview::text($text, 100),
            'text_length' => mb_strlen($text),
        ]);

        $startTime = microtime(true);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post('https://openrouter.ai/api/v1/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        $latencyMs = (microtime(true) - $startTime) * 1000;

        if (! $response->successful()) {
            try {
                ProviderLog::create([
                    'agent'        => 'embedding',
                    'model'        => $model,
                    'latency_ms'   => $latencyMs,
                    'total_tokens' => null,
                    'status'       => 'error',
                ]);
            } catch (\Throwable $e) {}

            $logger->error('OpenRouter embedding request failed', [
                'status' => $response->status(),
                'body_preview' => LogPreview::text($response->body(), 1000),
            ]);
            throw new RuntimeException('OpenRouter embeddings error '.$response->status().': '.$response->body());
        }

        $json = $response->json();
        $embedding = (array) ($json['data'][0]['embedding'] ?? []);

        try {
            ProviderLog::create([
                'agent'        => 'embedding',
                'model'        => $model,
                'latency_ms'   => $latencyMs,
                'total_tokens' => $json['usage']['total_tokens'] ?? null,
                'status'       => 'success',
            ]);
        } catch (\Throwable $e) {}

        $logger->info('OpenRouter embedding response received', [
            'embedding_dimensions' => count($embedding),
        ]);

        return $embedding;
    }
}
