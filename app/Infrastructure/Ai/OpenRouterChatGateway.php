<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\StructuredLogger;
use App\Application\Contracts\AiChatGateway;
use App\Support\LogPreview;
use App\Models\ProviderLog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterChatGateway implements AiChatGateway
{
    public function __construct(
        private readonly StructuredLogger $logger,
    ) {
    }

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

        $apiKey = trim((string) config('historia.ai.openrouter.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Falta OPENROUTER_API_KEY');
        }

        $timeoutSeconds = max(1, (int) ceil(($timeoutMs ?? 120000) / 1000));
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'component' => 'openrouter_chat_gateway',
            'provider' => 'openrouter',
            'model' => $model,
            'streaming' => $onChunk !== null,
        ]);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxOutputTokens,
        ];
        if (is_array($cacheControl) && $cacheControl !== []) {
            $payload['cache_control'] = $cacheControl;
        }
        if ($tools !== null && $tools !== []) {
            $payload['tools'] = $tools;
        }
        if (array_key_exists('top_p', $options) && is_numeric($options['top_p'])) {
            $payload['top_p'] = (float) $options['top_p'];
        }
        if (array_key_exists('presence_penalty', $options) && is_numeric($options['presence_penalty'])) {
            $payload['presence_penalty'] = (float) $options['presence_penalty'];
        }
        if (array_key_exists('frequency_penalty', $options) && is_numeric($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = (float) $options['frequency_penalty'];
        }
        if (array_key_exists('user', $options) && is_string($options['user']) && trim($options['user']) !== '') {
            $payload['user'] = trim($options['user']);
        }
        if (array_key_exists('session_id', $options) && is_string($options['session_id']) && trim($options['session_id']) !== '') {
            $payload['session_id'] = trim($options['session_id']);
        }
        if (array_key_exists('metadata', $options) && is_array($options['metadata']) && $options['metadata'] !== []) {
            $payload['metadata'] = $options['metadata'];
        }
        $reasoningEnabled = (bool) (($options['reasoning'] ?? [])['enabled'] ?? false);
        $payload['reasoning'] = ['enabled' => $reasoningEnabled];
        if ($reasoningEnabled) {
            $payload['temperature'] = 1.0;
            $budgetTokens = (int) ($options['reasoning']['budget_tokens'] ?? 0);
            if ($budgetTokens > 0) {
                $payload['max_tokens'] = $maxOutputTokens + $budgetTokens;
            }
        }

        $logger->debug('OpenRouter request prepared', [
            'request' => $this->buildRequestLogContext($payload, $timeoutSeconds),
        ]);

        if ($onChunk !== null) {
            return $this->streamChat($apiKey, $payload, $timeoutSeconds, $onChunk, $logger, $startTime, $agentName, $model);
        }

        $response = Http::timeout($timeoutSeconds)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://openrouter.ai/api/v1/chat/completions', $payload);

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

            $logger->error('OpenRouter request failed', [
                'status' => $response->status(),
                'body_preview' => LogPreview::text($response->body(), 4000),
            ]);
            throw new RuntimeException('OpenRouter error '.$response->status().': '.$response->body());
        }

        $json = $response->json();
        $totalTokens = $json['usage']['total_tokens'] ?? null;

        try {
            ProviderLog::create([
                'agent' => $agentName,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'total_tokens' => $totalTokens ?: null,
                'status' => 'success'
            ]);
        } catch (\Throwable $e) {}

        $message = $json['choices'][0]['message'] ?? [];

        $content = $message['content'] ?? '';
        $text = '';
        if (is_array($content)) {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'] ?? '';
                }
            }
        } else {
            $text = (string) $content;
        }

        $rawToolCalls = $message['tool_calls'] ?? [];
        $toolCalls = [];
        foreach ($rawToolCalls as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $tc['function']['name'] ?? '',
                    'arguments' => $tc['function']['arguments'] ?? '{}',
                ],
            ];
        }

        $logger->info('OpenRouter response received', $this->buildResponseLogContext($text, $json['usage'] ?? null, $json));

        return [
            'text' => $text,
            'usage' => $json['usage'] ?? null,
            'raw' => $json,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];
    }

    private function streamChat(string $apiKey, array $payload, int $timeoutSeconds, callable $onChunk, StructuredLogger $logger, float $startTime, string $agentName, string $model): array
    {
        $payload['stream'] = true;
        $client = new Client([
            'base_uri' => 'https://openrouter.ai',
            'timeout' => $timeoutSeconds,
            'read_timeout' => $timeoutSeconds,
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'text/event-stream',
                'Content-Type' => 'application/json',
            ],
        ]);
        try {
            $response = $client->post('/api/v1/chat/completions', [
                'json' => $payload,
                'stream' => true,
            ]);
        } catch (GuzzleException $exception) {
            $latencyMs = (microtime(true) - $startTime) * 1000;
            try {
                ProviderLog::create(['agent' => $agentName, 'model' => $model, 'latency_ms' => $latencyMs, 'status' => 'error']);
            } catch (\Throwable $e) {}

            $logger->error('OpenRouter streaming request failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
            throw new RuntimeException('OpenRouter streaming error: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->getStatusCode() >= 400) {
            $latencyMs = (microtime(true) - $startTime) * 1000;
            try {
                ProviderLog::create(['agent' => $agentName, 'model' => $model, 'latency_ms' => $latencyMs, 'status' => 'error']);
            } catch (\Throwable $e) {}

            $logger->error('OpenRouter streaming request returned error status', ['status' => $response->getStatusCode(), 'body_preview' => LogPreview::text($response->getBody()->getContents(), 4000)]);
            throw new RuntimeException('OpenRouter error '.$response->getStatusCode().': '.$response->getBody()->getContents());
        }

        $body = $response->getBody();
        $buffer = '';
        $text = '';
        $usage = null;
        $rawEvents = [];
        $toolCallsBuffer = [];
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newlinePosition), "\r");
                $buffer = substr($buffer, $newlinePosition + 1);
                if (! str_starts_with($line, 'data:')) continue;
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') break;
                $event = json_decode($data, true);
                if (! is_array($event)) continue;
                $rawEvents[] = $event;
                if (is_array($event['usage'] ?? null)) $usage = $event['usage'];
                $delta = $this->extractDeltaText($event);
                if ($delta !== '') { $text .= $delta; $onChunk($delta); }
                foreach ($event['choices'] ?? [] as $choice) {
                    foreach ($choice['delta']['tool_calls'] ?? [] as $tcDelta) {
                        $idx = $tcDelta['index'] ?? 0;
                        if (! isset($toolCallsBuffer[$idx])) {
                            $toolCallsBuffer[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                        }
                        if (isset($tcDelta['id'])) $toolCallsBuffer[$idx]['id'] .= $tcDelta['id'];
                        if (isset($tcDelta['function']['name'])) $toolCallsBuffer[$idx]['name'] .= $tcDelta['function']['name'];
                        if (isset($tcDelta['function']['arguments'])) $toolCallsBuffer[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                    }
                }
            }
        }

        $latencyMs = (microtime(true) - $startTime) * 1000;
        $totalTokens = $usage['total_tokens'] ?? null;
        try {
            ProviderLog::create([
                'agent' => $agentName,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'total_tokens' => $totalTokens,
                'status' => 'success'
            ]);
        } catch (\Throwable $e) {}

        $toolCalls = [];
        foreach ($toolCallsBuffer as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'],
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => $tc['arguments'],
                ],
            ];
        }
        $logger->info('OpenRouter streaming response received', $this->buildResponseLogContext($text, $usage, $rawEvents));
        return [
            'text' => $text,
            'usage' => $usage,
            'raw' => $rawEvents,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];
    }

    /**
     * @return array<int, float>
     */
    public function embeddings(string $model, string $text): array
    {
        $apiKey = trim((string) config('historia.ai.openrouter.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Falta OPENROUTER_API_KEY');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post('https://openrouter.ai/api/v1/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenRouter embeddings error '.$response->status().': '.$response->body());
        }

        $json = $response->json();

        return (array) ($json['data'][0]['embedding'] ?? []);
    }

    private function extractDeltaText(array $event): string
    {
        $pieces = [];
        foreach (($event['choices'] ?? []) as $choice) {
            $content = $choice['delta']['content'] ?? null;
            if (is_string($content)) { $pieces[] = $content; continue; }
            if (! is_array($content)) continue;
            foreach ($content as $part) {
                $candidate = $part['text'] ?? $part['content'] ?? null;
                if (is_string($candidate) && $candidate !== '') $pieces[] = $candidate;
            }
        }
        return implode('', $pieces);
    }

    private function buildRequestLogContext(array $payload, int $timeoutSeconds): array
    {
        return [
            'model' => $payload['model'] ?? null,
            'temperature' => $payload['temperature'] ?? null,
            'max_tokens' => $payload['max_tokens'] ?? null,
            'top_p' => $payload['top_p'] ?? null,
            'presence_penalty' => $payload['presence_penalty'] ?? null,
            'frequency_penalty' => $payload['frequency_penalty'] ?? null,
            'timeout_seconds' => $timeoutSeconds,
            'stream' => (bool) ($payload['stream'] ?? false),
            'cache_control' => $payload['cache_control'] ?? null,
            'user' => $payload['user'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'messages' => LogPreview::messages($payload['messages'] ?? [], 8000),
        ];
    }

    private function buildResponseLogContext(string $text, mixed $usage, mixed $raw): array
    {
        return [
            'text_preview' => LogPreview::text($text, 4000),
            'text_chars' => mb_strlen($text),
            'usage' => $usage,
            'raw_preview' => LogPreview::json($raw, 4000),
        ];
    }
}
