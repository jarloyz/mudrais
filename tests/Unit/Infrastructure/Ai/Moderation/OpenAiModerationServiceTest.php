<?php

namespace Tests\Unit\Infrastructure\Ai\Moderation;

use App\Infrastructure\Ai\Moderation\OpenAiModerationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenAiModerationServiceTest extends TestCase
{
    private OpenAiModerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpenAiModerationService();
        config(['services.openai.key' => 'fake-key']);
    }

    public function test_check_returns_flagged_true_when_api_flags_content()
    {
        Http::fake([
            'https://api.openai.com/v1/moderations' => Http::response([
                'results' => [
                    [
                        'flagged' => true,
                        'categories' => [
                            'hate' => true,
                            'sexual' => false,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->check('some hate speech');

        $this->assertTrue($result['flagged']);
        $this->assertTrue($result['categories']['hate']);
    }

    public function test_check_returns_flagged_false_when_api_does_not_flag_content()
    {
        Http::fake([
            'https://api.openai.com/v1/moderations' => Http::response([
                'results' => [
                    [
                        'flagged' => false,
                        'categories' => [
                            'hate' => false,
                            'sexual' => false,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->check('hello world');

        $this->assertFalse($result['flagged']);
    }

    public function test_check_fails_open_on_api_error()
    {
        Log::shouldReceive('warning')->once();

        Http::fake([
            'https://api.openai.com/v1/moderations' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $result = $this->service->check('some text');

        $this->assertFalse($result['flagged']);
    }

    public function test_check_fails_open_on_exception()
    {
        Log::shouldReceive('warning')->once();

        Http::fake([
            'https://api.openai.com/v1/moderations' => function () {
                throw new \Exception('Network error');
            },
        ]);

        $result = $this->service->check('some text');

        $this->assertFalse($result['flagged']);
    }

    public function test_check_returns_false_for_empty_text()
    {
        Http::assertNothingSent();

        $result = $this->service->check('');

        $this->assertFalse($result['flagged']);
    }

    public function test_check_fails_open_if_api_key_is_missing()
    {
        config(['services.openai.key' => null]);
        Log::shouldReceive('warning')->once();

        $result = $this->service->check('some text');

        $this->assertFalse($result['flagged']);
    }
}
