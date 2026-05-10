<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Models\ProviderLog;
use App\Support\LogPreview;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gateway genérico para cualquier servidor OpenAI-compatible (/v1/chat/completions).
 * Parametrizado por base_url y api_key, sin hardcodear endpoints.
 */
class OpenAiCompatibleChatGateway implements AiChatGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $presetName,
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
        $timeoutSeconds = max(1, (int) ceil(($timeoutMs ?? 120000) / 1000));
        $startTime = microtime(true);
        $agentName = (string) ($options['agent'] ?? $options['metadata']['agent'] ?? 'unknown');
        $logger = $this->logger->withContext([
            'layer'     => 'infrastructure',
            'component' => 'openai_compatible_chat_gateway',
            'preset'    => $this->presetName,
            'model'     => $model,
            'streaming' => $onChunk !== null,
        ]);

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
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
        // El parámetro 'thinking' (budget_tokens) es exclusivo de Anthropic Claude.
        // Los backends OpenAI-compatible ignoran el campo pero sí respetan max_tokens,
        // por lo que NO inflamos max_tokens con el budget — se usa el valor del caller.

        $logger->debug('OpenAI-compatible request prepared', [
            'request' => $this->buildRequestLogContext($payload, $timeoutSeconds),
        ]);

        if ($onChunk !== null) {
            return $this->streamChat($payload, $timeoutSeconds, $onChunk, $logger, $startTime, $agentName, $model);
        }

        $endpoint = $this->buildEndpoint('/chat/completions');
        $request = Http::timeout($timeoutSeconds)->acceptJson();
        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }
        $response = $request->post($endpoint, $payload);

        if (! $response->successful()) {
            $latencyMs = (microtime(true) - $startTime) * 1000;
            try {
                ProviderLog::create([
                    'agent'        => $agentName,
                    'model'        => $model,
                    'latency_ms'   => $latencyMs,
                    'total_tokens' => null,
                    'status'       => 'error',
                ]);
            } catch (\Throwable $e) {}

            $logger->error('OpenAI-compatible request failed', [
                'status'       => $response->status(),
                'body_preview' => LogPreview::text($response->body(), 4000),
            ]);
            throw new RuntimeException("[$this->presetName] error {$response->status()}: {$response->body()}");
        }

        $json    = $response->json();
        $message = $json['choices'][0]['message'] ?? [];
        $content = $message['content'] ?? '';
        $text    = '';
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
        $toolCalls    = [];
        foreach ($rawToolCalls as $tc) {
            $toolCalls[] = [
                'id'       => $tc['id'] ?? '',
                'type'     => 'function',
                'function' => [
                    'name'      => $tc['function']['name'] ?? '',
                    'arguments' => $tc['function']['arguments'] ?? '{}',
                ],
            ];
        }

        $logger->info('OpenAI-compatible response received', $this->buildResponseLogContext($text, $json['usage'] ?? null, $json));

        $latencyMs = (microtime(true) - $startTime) * 1000;
        $totalTokens = $json['usage']['total_tokens'] ?? null;
        try {
            ProviderLog::create([
                'agent'        => $agentName,
                'model'        => $model,
                'latency_ms'   => $latencyMs,
                'total_tokens' => $totalTokens ?: null,
                'status'       => 'success',
            ]);
        } catch (\Throwable $e) {}

        return [
            'text'       => $text,
            'usage'      => $json['usage'] ?? null,
            'raw'        => $json,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];
    }

    private function streamChat(array $payload, int $timeoutSeconds, callable $onChunk, StructuredLogger $logger, float $startTime, string $agentName, string $model): array
    {
        $payload['stream'] = true;
        $baseUri  = rtrim($this->baseUrl, '/');
        $headers  = [
            'Accept'       => 'text/event-stream',
            'Content-Type' => 'application/json',
        ];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        $client = new Client([
            'base_uri' => $baseUri,
            'timeout'  => $timeoutSeconds,
            'read_timeout' => $timeoutSeconds,
            'headers'  => $headers,
        ]);

        try {
            $response = $client->post('/chat/completions', [
                'json'   => $payload,
                'stream' => true,
            ]);
        } catch (GuzzleException $exception) {
            $latencyMs = (microtime(true) - $startTime) * 1000;
            try {
                ProviderLog::create([
                    'agent'        => $agentName,
                    'model'        => $model,
                    'latency_ms'   => $latencyMs,
                    'total_tokens' => null,
                    'status'       => 'error',
                ]);
            } catch (\Throwable $e) {}

            $logger->error("[$this->presetName] streaming request failed", [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);
            throw new RuntimeException("[$this->presetName] streaming error: {$exception->getMessage()}", 0, $exception);
        }

        if ($response->getStatusCode() >= 400) {
            $body = $response->getBody()->getContents();
            $latencyMs = (microtime(true) - $startTime) * 1000;
            try {
                ProviderLog::create([
                    'agent'        => $agentName,
                    'model'        => $model,
                    'latency_ms'   => $latencyMs,
                    'total_tokens' => null,
                    'status'       => 'error',
                ]);
            } catch (\Throwable $e) {}

            $logger->error("[$this->presetName] streaming error status", [
                'status'       => $response->getStatusCode(),
                'body_preview' => LogPreview::text($body, 4000),
            ]);
            throw new RuntimeException("[$this->presetName] error {$response->getStatusCode()}: {$body}");
        }

        $body            = $response->getBody();
        $buffer          = '';
        $text            = '';
        $usage           = null;
        $rawEvents       = [];
        $toolCallsBuffer = [];

        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    break;
                }
                $event = json_decode($data, true);
                if (! is_array($event)) {
                    continue;
                }
                $rawEvents[] = $event;
                if (is_array($event['usage'] ?? null)) {
                    $usage = $event['usage'];
                }
                $delta = $this->extractDeltaText($event);
                if ($delta !== '') {
                    $text .= $delta;
                    $onChunk($delta);
                }
                foreach ($event['choices'] ?? [] as $choice) {
                    foreach ($choice['delta']['tool_calls'] ?? [] as $tcDelta) {
                        $idx = $tcDelta['index'] ?? 0;
                        if (! isset($toolCallsBuffer[$idx])) {
                            $toolCallsBuffer[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                        }
                        if (isset($tcDelta['id'])) {
                            $toolCallsBuffer[$idx]['id'] .= $tcDelta['id'];
                        }
                        if (isset($tcDelta['function']['name'])) {
                            $toolCallsBuffer[$idx]['name'] .= $tcDelta['function']['name'];
                        }
                        if (isset($tcDelta['function']['arguments'])) {
                            $toolCallsBuffer[$idx]['arguments'] .= $tcDelta['function']['arguments'];
                        }
                    }
                }
            }
        }

        $toolCalls = [];
        foreach ($toolCallsBuffer as $tc) {
            $toolCalls[] = [
                'id'       => $tc['id'],
                'type'     => 'function',
                'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']],
            ];
        }

        $logger->info("[$this->presetName] streaming response received", $this->buildResponseLogContext($text, $usage, $rawEvents));

        $latencyMs = (microtime(true) - $startTime) * 1000;
        try {
            ProviderLog::create([
                'agent'        => $agentName,
                'model'        => $model,
                'latency_ms'   => $latencyMs,
                'total_tokens' => $usage['total_tokens'] ?? null,
                'status'       => 'success',
            ]);
        } catch (\Throwable $e) {}

        return [
            'text'       => $text,
            'usage'      => $usage,
            'raw'        => $rawEvents,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];
    }

    /**
     * @return array<int, float>
     */
    public function embeddings(string $model, string $text): array
    {
        $endpoint  = $this->buildEndpoint('/embeddings');
        $request   = Http::acceptJson();
        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $startTime = microtime(true);
        $response  = $request->post($endpoint, [
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

            throw new RuntimeException("[$this->presetName] embeddings error {$response->status()}: {$response->body()}");
        }

        $json = $response->json();

        try {
            ProviderLog::create([
                'agent'        => 'embedding',
                'model'        => $model,
                'latency_ms'   => $latencyMs,
                'total_tokens' => $json['usage']['total_tokens'] ?? null,
                'status'       => 'success',
            ]);
        } catch (\Throwable $e) {}

        return (array) ($json['data'][0]['embedding'] ?? []);
    }

    private function extractDeltaText(array $event): string
    {
        $pieces = [];
        foreach (($event['choices'] ?? []) as $choice) {
            $content = $choice['delta']['content'] ?? null;
            if (is_string($content)) {
                $pieces[] = $content;
                continue;
            }
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                $candidate = $part['text'] ?? $part['content'] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    $pieces[] = $candidate;
                }
            }
        }

        return implode('', $pieces);
    }

    /**
     * Construye el endpoint final. Si base_url ya incluye la ruta (ej. .../embeddings),
     * la usa directamente. Permite poner la URL completa en base_url para servidores no-estándar.
     */
    private function buildEndpoint(string $path): string
    {
        $base = rtrim($this->baseUrl, '/');
        if (str_ends_with($base, $path)) {
            return $base;
        }
        return $base.$path;
    }

    private function buildRequestLogContext(array $payload, int $timeoutSeconds): array
    {
        return [
            'preset'           => $this->presetName,
            'base_url'         => $this->baseUrl,
            'model'            => $payload['model'] ?? null,
            'temperature'      => $payload['temperature'] ?? null,
            'max_tokens'       => $payload['max_tokens'] ?? null,
            'top_p'            => $payload['top_p'] ?? null,
            'presence_penalty' => $payload['presence_penalty'] ?? null,
            'frequency_penalty' => $payload['frequency_penalty'] ?? null,
            'timeout_seconds'  => $timeoutSeconds,
            'stream'           => (bool) ($payload['stream'] ?? false),
            'messages'         => LogPreview::messages($payload['messages'] ?? [], 8000),
        ];
    }

    private function buildResponseLogContext(string $text, mixed $usage, mixed $raw): array
    {
        return [
            'text_preview' => LogPreview::text($text, 4000),
            'text_chars'   => mb_strlen($text),
            'usage'        => $usage,
            'raw_preview'  => LogPreview::json($raw, 4000),
        ];
    }
}
