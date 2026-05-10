<?php

namespace Tests\Unit\Support;

use App\Support\OpenRouterModelCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterModelCatalogTest extends TestCase
{
    public function test_fetches_models_and_formats_cost_labels(): void
    {
        Cache::flush();

        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'openai/gpt-4.1-mini',
                        'name' => 'GPT-4.1 Mini',
                        'description' => 'Fast model',
                        'context_length' => 1047576,
                        'top_provider' => [
                            'max_completion_tokens' => 32768,
                        ],
                        'pricing' => [
                            'prompt' => '0.0000004',
                            'completion' => '0.0000016',
                            'request' => '0',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $catalog = app(OpenRouterModelCatalog::class);
        $models = $catalog->all(forceRefresh: true);

        $this->assertCount(1, $models);
        $this->assertSame('openai/gpt-4.1-mini', $models[0]['id']);
        $this->assertSame('in $0.40/M · out $1.60/M', $models[0]['price_label']);
        $this->assertArrayHasKey('openai/gpt-4.1-mini', $catalog->selectOptions());
    }

    public function test_detects_free_model_from_zero_pricing(): void
    {
        Cache::flush();

        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'meta-llama/free-model',
                        'name' => 'Free Model',
                        'pricing' => [
                            'prompt' => '0',
                            'completion' => '0',
                            'request' => '0',
                        ],
                    ],
                    [
                        'id' => 'openai/paid-model',
                        'name' => 'Paid Model',
                        'pricing' => [
                            'prompt' => '0.000001',
                            'completion' => '0.000002',
                            'request' => '0',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $catalog = app(OpenRouterModelCatalog::class);

        $this->assertTrue($catalog->isFreeModel('meta-llama/free-model', forceRefresh: true));
        $this->assertFalse($catalog->isFreeModel('openai/paid-model'));
    }
}
