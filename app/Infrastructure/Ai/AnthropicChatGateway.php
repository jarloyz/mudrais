<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Models\ProviderLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicChatGateway implements AiChatGateway
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
        $startTime = microtime(true);
        $agentName = $options['agent'] ?? $options['metadata']['agent'] ?? 'unknown';

        $apiKey = trim((string) config('historia.ai.anthropic.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Falta ANTHROPIC_API_KEY');
        }

        $system = collect($messages)
            ->where('role', 'system')
            ->pluck('content')
            ->implode("\n\n");

        $anthropicMessages = collect($messages)
            ->filter(fn (array $message): bool => in_array($message['role'] ?? '', ['user', 'assistant', 'tool'], true))
            ->map(function (array $message): array {
                $role = $message['role'];
                $content = $message['content'] ?? '';

                if ($role === 'tool') {
                    return [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'tool_result',
                                'tool_use_id' => $message['tool_call_id'] ?? '',
                                'content' => (string) $content,
                            ],
                        ],
                    ];
                }

                if (is_array($content)) {
                    return [
                        'role' => $role,
                        'content' => $content,
                    ];
                }

                if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                    $blocks = [];
                    if ($content !== '') {
                        $blocks[] = ['type' => 'text', 'text' => (string) $content];
                    }
                    foreach ($message['tool_calls'] as $tc) {
                        $blocks[] = [
                            'type' => 'tool_use',
                            'id' => $tc['id'],
                            'name' => $tc['function']['name'],
                            'input' => json_decode($tc['function']['arguments'], true) ?? [],
                        ];
                    }
                    return [
                        'role' => 'assistant',
                        'content' => $blocks,
                    ];
                }

                return [
                    'role' => $role,
                    'content' => (string) $content,
                ];
            })
            ->values()
            ->all();

        $timeoutSeconds = max(1, (int) ceil(($timeoutMs ?? 120000) / 1000));

        $thinkingOpt = $options['thinking'] ?? null;
        $thinkingActive = is_array($thinkingOpt) && ($thinkingOpt['type'] ?? '') === 'enabled';
        $budgetTokens = $thinkingActive ? (int) ($thinkingOpt['budget_tokens'] ?? 8000) : 0;

        if ($thinkingActive) {
            $temperature = 1.0;                          // Anthropic lo exige cuando thinking está activo
            $maxOutputTokens = $budgetTokens + $maxOutputTokens; // cuota total = thinking + texto
        }

        $payload = [
            'model' => $model,
            'system' => $system !== '' ? $system : null,
            'messages' => $anthropicMessages,
            'temperature' => $temperature,
            'max_tokens' => $maxOutputTokens,
        ];

        if ($thinkingActive) {
            $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budgetTokens];
        }

        if ($tools !== null && $tools !== []) {
            $payload['tools'] = array_map(function ($tool) {
                if (($tool['type'] ?? '') === 'function') {
                    return [
                        'name' => $tool['function']['name'],
                        'description' => $tool['function']['description'] ?? '',
                        'input_schema' => $tool['function']['parameters'] ?? [
                            'type' => 'object',
                            'properties' => (object) [],
                        ],
                    ];
                }
                return $tool;
            }, $tools);
        }

        $response = Http::timeout($timeoutSeconds)
            ->withHeaders(array_merge([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ], $thinkingActive ? ['anthropic-beta' => 'interleaved-thinking-2025-05-14'] : []))
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', $payload);

        $latencyMs = (microtime(true) - $startTime) * 1000;

        if (! $response->successful()) {
            try {
                ProviderLog::create([
                    'agent' => $agentName,
                    'model' => $model,
                    'latency_ms' => $latencyMs,
                    'total_tokens' => null,
                    'status' => 'error'
                ]);
            } catch (\Throwable $e) {}

            throw new RuntimeException('Anthropic error '.$response->status().': '.$response->body());
        }

        $json = $response->json();
        $totalTokens = null;
        if (isset($json['usage'])) {
            $input = $json['usage']['input_tokens'] ?? 0;
            $output = $json['usage']['output_tokens'] ?? 0;
            $totalTokens = $input + $output;
        }

        try {
            ProviderLog::create([
                'agent' => $agentName,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'total_tokens' => $totalTokens ?: null,
                'status' => 'success'
            ]);
        } catch (\Throwable $e) {}

        $text = '';
        $toolCalls = [];
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'],
                        'arguments' => json_encode($block['input'], JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }

        $result = [
            'text' => $text,
            'usage' => $json['usage'] ?? null,
            'raw' => $json,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];

        if ($onChunk !== null && $text !== '') {
            $onChunk($text);
        }

        return $result;
    }

    /**
     * @return array<int, float>
     */
    public function embeddings(string $model, string $text): array
    {
        throw new RuntimeException('Anthropic no soporta embeddings actualmente a través de este gateway.');
    }
}
