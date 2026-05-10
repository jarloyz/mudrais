<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenRouterModelCatalog
{
    private const CACHE_KEY = 'openrouter.model_catalog.v1';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $fetched = $this->fetchFromApi();

            if ($fetched !== []) {
                return $fetched;
            }

            return $this->fallbackFromLegacyConfig();
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function lookup(bool $forceRefresh = false): array
    {
        $lookup = [];

        foreach ($this->all($forceRefresh) as $model) {
            $lookup[(string) $model['id']] = $model;
        }

        return $lookup;
    }

    /**
     * @return array<string, string>
     */
    public function selectOptions(bool $forceRefresh = false): array
    {
        $options = [];

        foreach ($this->all($forceRefresh) as $model) {
            $options[(string) $model['id']] = (string) $model['option_label'];
        }

        return $options;
    }

    public function isFreeModel(string $modelId, bool $forceRefresh = false): ?bool
    {
        $model = $this->lookup($forceRefresh)[$modelId] ?? null;

        if (! is_array($model)) {
            return null;
        }

        $prompt = (float) ($model['pricing']['prompt'] ?? 0);
        $completion = (float) ($model['pricing']['completion'] ?? 0);
        $request = (float) ($model['pricing']['request'] ?? 0);

        return $prompt <= 0.0 && $completion <= 0.0 && $request <= 0.0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFromApi(): array
    {
        try {
            $apiKey = trim((string) config('historia.ai.openrouter.api_key'));

            $request = Http::acceptJson()->timeout(20);

            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get('https://openrouter.ai/api/v1/models');

            if (! $response->successful()) {
                return [];
            }

            $payload = $response->json();
            $models = is_array($payload['data'] ?? null) ? $payload['data'] : [];

            return array_values(array_filter(array_map(
                fn (mixed $model): ?array => is_array($model) ? $this->normalize($model) : null,
                $models,
            )));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function normalize(array $model): array
    {
        $id = (string) ($model['id'] ?? '');
        $name = (string) ($model['name'] ?? $id);
        $description = (string) ($model['description'] ?? '');
        $contextLength = (int) ($model['context_length'] ?? 0);
        $maxOutput = (int) ($model['top_provider']['max_completion_tokens'] ?? 0);
        $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];
        $prompt = (string) ($pricing['prompt'] ?? '0');
        $completion = (string) ($pricing['completion'] ?? '0');
        $request = (string) ($pricing['request'] ?? '0');

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'context_length' => $contextLength,
            'max_completion_tokens' => $maxOutput,
            'pricing' => [
                'prompt' => $prompt,
                'completion' => $completion,
                'request' => $request,
            ],
            'price_label' => $this->formatPricing($prompt, $completion, $request),
            'option_label' => $name.' · '.$this->formatPricing($prompt, $completion, $request),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackFromLegacyConfig(): array
    {
        $legacy = json_decode((string) @file_get_contents(base_path('../config.json')), true);
        $models = is_array($legacy['models'] ?? null) ? $legacy['models'] : [];
        $seen = [];
        $fallback = [];

        foreach ($models as $modelId) {
            $modelId = trim((string) $modelId);

            if ($modelId === '' || isset($seen[$modelId])) {
                continue;
            }

            $seen[$modelId] = true;
            $fallback[] = [
                'id' => $modelId,
                'name' => $modelId,
                'description' => 'Modelo disponible desde la configuración legacy del proyecto.',
                'context_length' => 0,
                'max_completion_tokens' => 0,
                'pricing' => [
                    'prompt' => '0',
                    'completion' => '0',
                    'request' => '0',
                ],
                'price_label' => 'Costo no disponible',
                'option_label' => $modelId.' · Costo no disponible',
            ];
        }

        return $fallback;
    }

    private function formatPricing(string $prompt, string $completion, string $request): string
    {
        $promptPerMillion = $this->toPerMillion($prompt);
        $completionPerMillion = $this->toPerMillion($completion);
        $requestCost = (float) $request;

        $parts = [];
        $parts[] = 'in $'.number_format($promptPerMillion, 2).'/M';
        $parts[] = 'out $'.number_format($completionPerMillion, 2).'/M';

        if ($requestCost > 0) {
            $parts[] = 'req $'.number_format($requestCost, 4);
        }

        return implode(' · ', $parts);
    }

    private function toPerMillion(string $pricePerToken): float
    {
        return ((float) $pricePerToken) * 1000000;
    }
}
