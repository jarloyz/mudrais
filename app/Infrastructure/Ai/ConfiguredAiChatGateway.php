<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\StructuredLogger;
use App\Models\AgentConfig;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ConfiguredAiChatGateway implements AiChatGateway
{
    public function __construct(
        private readonly AnthropicChatGateway $anthropicChatGateway,
        private readonly OllamaAiGateway $ollamaAiGateway,
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
        $providerOverride = isset($options['_provider']) ? (string) $options['_provider'] : null;
        unset($options['_provider']);

        return $this->resolveActiveGateway($providerOverride)->chat($model, $messages, $temperature, $maxOutputTokens, $timeoutMs, $cacheControl, $onChunk, $options, $tools);
    }

    /**
     * @return array<int, float>
     */
    public function embeddings(string $model, string $text): array
    {
        return $this->resolveActiveGateway()->embeddings($model, $text);
    }

    private function resolveActiveGateway(?string $providerOverride = null): AiChatGateway
    {
        $slug = $providerOverride ?? Cache::remember('ai_active_provider', 60, static function (): string {
            return (string) (AgentConfig::globalInstance()->provider
                ?? config('historia.ai.provider', 'openrouter'));
        });

        return match ($slug) {
            'anthropic' => $this->anthropicChatGateway,
            'ollama'    => $this->ollamaAiGateway,
            default     => $this->buildOpenAiCompatibleGateway($slug),
        };
    }

    private function buildOpenAiCompatibleGateway(string $slug): OpenAiCompatibleChatGateway
    {
        // Cacheamos solo strings primitivos, no objetos Eloquent, para evitar
        // problemas de serialización/deserialización en Redis tras migraciones.
        $data = Cache::remember("ai_provider_{$slug}", 60, static function () use ($slug): ?array {
            $preset = AiProvider::findBySlug($slug);
            if ($preset === null) {
                return null;
            }
            return ['base_url' => $preset->base_url, 'api_key' => (string) ($preset->api_key ?? '')];
        });

        if (! is_array($data)) {
            Cache::forget("ai_provider_{$slug}");
            Log::error("[ConfiguredAiChatGateway] Preset AI no encontrado en DB: {$slug}");
            throw new RuntimeException("Preset AI no encontrado en DB: {$slug}");
        }

        return new OpenAiCompatibleChatGateway(
            baseUrl:    rtrim($data['base_url'], '/'),
            apiKey:     $data['api_key'],
            presetName: $slug,
            logger:     $this->logger,
        );
    }
}
