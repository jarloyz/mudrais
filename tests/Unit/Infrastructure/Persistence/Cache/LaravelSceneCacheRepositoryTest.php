<?php

namespace Tests\Unit\Infrastructure\Persistence\Cache;

use App\Infrastructure\Persistence\Cache\LaravelSceneCacheRepository;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LaravelSceneCacheRepositoryTest extends TestCase
{
    public function test_puts_and_gets_payload_using_laravel_cache(): void
    {
        Cache::flush();

        $repository = new LaravelSceneCacheRepository(Cache::store(config('cache.default')));
        $repository->put('simple_chat_memory', 'scene_demo', [
            'scene_key' => 'scene_demo',
            'rolling_summary' => 'Resumen cacheado.',
        ]);

        $payload = $repository->get('simple_chat_memory', 'scene_demo');

        $this->assertIsArray($payload);
        $this->assertSame('Resumen cacheado.', $payload['rolling_summary']);
    }
}
