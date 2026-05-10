<?php

namespace Tests\Unit\Support;

use App\Infrastructure\Logging\LaravelStructuredLogger;
use PHPUnit\Framework\TestCase;

class LaravelStructuredLoggerTest extends TestCase
{
    public function test_with_context_returns_new_logger_with_merged_context(): void
    {
        $logger = new LaravelStructuredLogger(['continuityId' => 'cont_1']);
        $scoped = $logger->withContext([
            'sceneId' => 'scene_1',
            'turnIndex' => 3,
        ]);

        $reflection = new \ReflectionClass($scoped);
        $property = $reflection->getProperty('baseContext');
        $property->setAccessible(true);

        $this->assertSame([
            'continuityId' => 'cont_1',
            'sceneId' => 'scene_1',
            'turnIndex' => 3,
        ], $property->getValue($scoped));
    }
}
