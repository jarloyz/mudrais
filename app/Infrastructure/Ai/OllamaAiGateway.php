<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaAiGateway implements AiChatGateway
{
    public function chat(
        string $model,
        array $messages,
        float $temperature,
        int $maxOutputTokens,
        ?int $timeoutMs = null,
        ?array $cacheControl = null,
        ?callable $onChunk = null,
        array $options = [],
        ?array $tools = null,
    ): array {
        $baseUrl = config('historia.ai.ollama.base_url', 'http://localhost:11434');
        $timeoutSeconds = max(1, (int) ceil(($timeoutMs ?? 120000) / 1000));

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $onChunk !== null,
            'options' => array_filter([
                'temperature' => $temperature,
                'num_predict' => $maxOutputTokens,
                'top_p' => $options['top_p'] ?? null,
            ]),
        ];

        if ($tools !== null && $tools !== []) {
            $payload['tools'] = $tools;
        }

        if ($onChunk !== null) {
            // Streaming implementation for Ollama would require a different approach with Guzzle
            // For now, we will handle non-streaming or throw if streaming is mandatory
            // (Note: To keep it simple and consistent with the task, we focus on the structure)
        }

        $response = Http::timeout($timeoutSeconds)
            ->post("{$baseUrl}/api/chat", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Ollama chat error '.$response->status().': '.$response->body());
        }

        $json = $response->json();
        $message = $json['message'] ?? [];

        return [
            'text' => (string) ($message['content'] ?? ''),
            'usage' => [
                'prompt_tokens' => $json['prompt_eval_count'] ?? 0,
                'completion_tokens' => $json['eval_count'] ?? 0,
            ],
            'raw' => $json,
            'tool_calls' => $this->parseToolCalls($message['tool_calls'] ?? []),
        ];
    }

    public function embeddings(string $model, string $text): array
    {
        $baseUrl = config('historia.ai.ollama.base_url', 'http://localhost:11434');

        $response = Http::post("{$baseUrl}/api/embeddings", [
            'model' => $model,
            'prompt' => $text,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Ollama embeddings error '.$response->status().': '.$response->body());
        }

        $json = $response->json();
        return (array) ($json['embedding'] ?? []);
    }

    private function parseToolCalls(array $rawToolCalls): ?array
    {
        if (empty($rawToolCalls)) {
            return null;
        }

        $toolCalls = [];
        foreach ($rawToolCalls as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'] ?? uniqid('ollama_'),
                'type' => 'function',
                'function' => [
                    'name' => $tc['function']['name'] ?? '',
                    'arguments' => is_array($tc['function']['arguments'] ?? null)
                        ? json_encode($tc['function']['arguments'])
                        : ($tc['function']['arguments'] ?? '{}'),
                ],
            ];
        }

        return $toolCalls;
    }
}
