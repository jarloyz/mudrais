<?php

namespace Tests\Feature\Api\V2;

use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Infrastructure\Ai\Moderation\OpenAiModerationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Mockery\MockInterface;

class ChatModerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'fake-key']);
    }

    public function test_chat_blocked_when_moderation_flags_message()
    {
        Http::fake([
            'https://api.openai.com/v1/moderations' => Http::response([
                'results' => [
                    [
                        'flagged' => true,
                        'categories' => ['hate' => true],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v2/chat', [
            'scene_id' => 'scene-123',
            'user_message' => 'mensaje ofensivo',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'Tu mensaje no puede ser procesado porque infringe las políticas de contenido.',
        ]);
    }

    public function test_chat_allowed_when_moderation_passes()
    {
        Http::fake([
            'https://api.openai.com/v1/moderations' => Http::response([
                'results' => [
                    [
                        'flagged' => false,
                        'categories' => [],
                    ],
                ],
            ], 200),
        ]);

        // Usamos un objeto anónimo porque GenerateSceneTurnUseCase es final y no se puede mockear directamente
        $fakeUseCase = new class {
            public function execute($sceneId, $userMessage, $mode = 'write_scene', $apply = true, $userId = null, $onChunk = null, $qaLoop = null, $onProgress = null): array {
                return [
                    'sceneId' => 'scene-123',
                    'outputMd' => 'Respuesta del bot',
                    'applied' => true,
                    'sceneType' => 'simple'
                ];
            }
        };

        $this->app->bind(GenerateSceneTurnUseCase::class, function () use ($fakeUseCase) {
            return $fakeUseCase;
        });

        $response = $this->postJson('/api/v2/chat', [
            'scene_id' => 'scene-123',
            'user_message' => 'hola',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['sceneId' => 'scene-123']);
    }
}
