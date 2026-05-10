<?php

namespace App\Infrastructure\Ai;

use App\Application\Contracts\EmbeddingGateway;
use App\Application\Contracts\StructuredLogger;
use App\Models\AgentConfig;
use App\Models\AiProvider;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ConfiguredEmbeddingGateway implements EmbeddingGateway
{
    public function __construct(
        private readonly OpenRouterEmbeddingGateway $openRouterEmbeddingGateway,
        private readonly StructuredLogger $logger,
        private readonly UserAiSettingsResolver $settingsResolver,
    ) {
    }

    /**
     * @return array<int, float>
     */
    public function embed(string $model, string $text): array
    {
        // Per-agent provider override for 'embedding' agent key takes priority
        $agentProvider = $this->settingsResolver->resolveAgentProvider(null, 'embedding');

        $slug = $agentProvider ?? Cache::remember('ai_active_provider', 60, static function (): string {
            return (string) (AgentConfig::globalInstance()->provider
                ?? config('historia.ai.provider', 'openrouter'));
        });

        // Los drivers nativos con gateway propio no usan OpenAiCompatibleChatGateway.
        if (in_array($slug, ['anthropic', 'ollama'], true)) {
            return $this->openRouterEmbeddingGateway->embed($model, $text);
        }

        $data = Cache::remember("ai_provider_{$slug}", 60, static function () use ($slug): ?array {
            $preset = AiProvider::findBySlug($slug);
            if ($preset === null) {
                return null;
            }
            return ['base_url' => $preset->base_url, 'api_key' => (string) ($preset->api_key ?? '')];
        });

        if (! is_array($data)) {
            Cache::forget("ai_provider_{$slug}");
            throw new RuntimeException("Preset AI no encontrado en DB para embeddings: {$slug}");
        }

        $gateway = new OpenAiCompatibleChatGateway(
            baseUrl:    rtrim($data['base_url'], '/'),
            apiKey:     $data['api_key'],
            presetName: $slug,
            logger:     $this->logger,
        );

        return $gateway->embeddings($model, $text);
    }
}
