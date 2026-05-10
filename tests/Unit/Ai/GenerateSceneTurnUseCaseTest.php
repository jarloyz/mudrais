<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\QaLoopRunner;
use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Domain\Scene\SceneCharacter;
use App\Infrastructure\Persistence\Cache\FilesystemSceneCacheRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Models\Player;
use App\Models\AgentConfig;
use App\Support\SimpleChatMemoryManager;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class GenerateSceneTurnUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private function qaLoopRunnerStub(): QaLoopRunner
    {
        return new class implements QaLoopRunner
        {
            public function run(Activity $scene, array $context, string $userMessage, string $mode, string $outputMd, array $qaLoop, ?string $userId = null): array
            {
                return [
                    'enabled' => (bool) ($qaLoop['enabled'] ?? false),
                    'triggered' => false,
                    'passes' => (bool) ($qaLoop['enabled'] ?? false) ? 1 : 0,
                    'highestSeverity' => 'none',
                    'status' => (bool) ($qaLoop['enabled'] ?? false) ? 'approved' : 'disabled',
                    'issues' => [],
                    'outputMd' => $outputMd,
                ];
            }
        };
    }

    public function test_generates_and_appends_simple_scene_turn_and_populates_cache(): void
    {
        config()->set('historia.cache.root', sys_get_temp_dir().'/historia_cache_'.uniqid('', true));

        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_ai', 'Vault AI'));
        (new EloquentCharacterRepository())->save(new Avatar(
            id: 'ana',
            vaultId: 'vault_ai',
            name: 'Ana',
        ));
        $sceneRepository = new EloquentSceneRepository();
        $sceneRepository->save(Activity::fromArray([
            'id' => 'scene_ai',
            'vaultId' => 'vault_ai',
            'title' => 'Prueba',
            'objective' => 'Escena de prueba',
            'draft' => 'Borrador previo',
            'characters' => [new SceneCharacter('ana', 'protagonist')],
        ]));

        $gateway = new class implements AiChatGateway
        {
            public string $lastModel = '';

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->lastModel = $model;

                if ($onChunk !== null) {
                    $onChunk('Ana entro en silencio y miro la puerta.');
                }

                return [
                    'text' => "Ana entro en silencio y miro la puerta.",
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => null,
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'provider'     => 'openrouter',
            'writer_model' => 'openrouter/custom-writer',
            'qa_model'     => 'openrouter/custom-qa',
            'timeout_ms'   => 90000,
        ]);

        $agentGateway = new \App\Infrastructure\Ai\Agents\LaravelAgentGateway(
            new \App\Infrastructure\Ai\Agents\SimpleSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger()),
            new \App\Infrastructure\Ai\Agents\ComplexSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger())
        );

        $useCase = new GenerateSceneTurnUseCase(
            sceneRepository: $sceneRepository,
            sceneContextBuilder: new EloquentSceneContextBuilder(),
            sceneCacheRepository: new FilesystemSceneCacheRepository(new Filesystem()),
            agentGateway: $agentGateway,
            qaLoopRunner: $this->qaLoopRunnerStub(),
            logger: new ArrayStructuredLogger(),
            simpleChatMemoryManager: new SimpleChatMemoryManager(new FilesystemSceneCacheRepository(new Filesystem())),
        );

        $result = $useCase->execute('scene_ai', 'Continua la escena', 'write_scene', true, $player->id);
        $updatedScene = $sceneRepository->findById('scene_ai');
        $cacheRoot = (string) config('historia.cache.root');

        $this->assertSame('simple', $result['sceneType']);
        $this->assertSame('openrouter/custom-writer', $gateway->lastModel);
        $this->assertStringContainsString('Ana entro en silencio', $result['outputMd']);
        $this->assertStringContainsString('Borrador previo', (string) $updatedScene?->draft);
        $this->assertStringContainsString('Ana entro en silencio', (string) $updatedScene?->draft);
        $this->assertFileExists($cacheRoot.'/scene_context/scene_ai.json');
        $this->assertFileExists($cacheRoot.'/evidence_summary/scene_ai.json');
    }

    public function test_simple_use_case_enriches_context_from_existing_memory(): void
    {
        config()->set('historia.cache.root', sys_get_temp_dir().'/historia_cache_'.uniqid('', true));

        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_memory', 'Vault Memory'));
        $sceneRepository = new EloquentSceneRepository();
        $sceneRepository->save(Activity::fromArray([
            'id' => 'scene_memory',
            'vaultId' => 'vault_memory',
            'title' => 'Memoria',
            'objective' => 'Probar resumen incremental',
            'draft' => 'La escena inicia con Ana en la cocina.',
            'characters' => [],
        ]));

        $cacheRepository = new FilesystemSceneCacheRepository(new Filesystem());
        $cacheRepository->put('simple_chat_memory', 'scene_memory', [
            'scene_key' => 'scene_memory',
            'scene_opening' => 'Inicio persistido.',
            'rolling_summary' => 'Resumen incremental previo.',
            'recent_messages' => [
                ['role' => 'user', 'content' => 'Hola'],
                ['role' => 'assistant', 'content' => 'Respuesta uno'],
                ['role' => 'user', 'content' => 'Que sucede ahora?'],
                ['role' => 'assistant', 'content' => 'Respuesta dos'],
            ],
            'unsummarized_messages' => [],
        ]);

        $gateway = new class implements AiChatGateway
        {
            public array $lastMessages = [];

            public function chat(string $model, array $messages, float $temperature, int $maxOutputTokens, ?int $timeoutMs = null, ?array $cacheControl = null, ?callable $onChunk = null, array $options = [], ?array $tools = null): array
            {
                $this->lastMessages = $messages;

                return [
                    'text' => 'Nueva respuesta del asistente.',
                    'usage' => null,
                    'raw' => null,
                    'tool_calls' => null,
                ];
            }

            public function embeddings(string $model, string $text): array
            {
                return [];
            }
        };

        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'provider'     => 'openrouter',
            'writer_model' => 'x-ai/grok-4.1-fast',
        ]);

        $useCase = new GenerateSceneTurnUseCase(
            sceneRepository: $sceneRepository,
            sceneContextBuilder: new EloquentSceneContextBuilder(),
            sceneCacheRepository: $cacheRepository,
            agentGateway: new \App\Infrastructure\Ai\Agents\LaravelAgentGateway(
                new \App\Infrastructure\Ai\Agents\SimpleSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger()),
                new \App\Infrastructure\Ai\Agents\ComplexSceneWriterAgent($gateway, new UserAiSettingsResolver(), new ArrayStructuredLogger())
            ),
            qaLoopRunner: $this->qaLoopRunnerStub(),
            logger: new ArrayStructuredLogger(),
            simpleChatMemoryManager: new SimpleChatMemoryManager($cacheRepository),
        );

        $useCase->execute('scene_memory', 'Nuevo mensaje del usuario.', 'write_scene', true, $player->id);

        $joinedPayload = json_encode($gateway->lastMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($joinedPayload);
        $this->assertStringContainsString('Inicio persistido.', $joinedPayload);
        $this->assertStringContainsString('Resumen incremental previo.', $joinedPayload);
        $this->assertStringContainsString('Respuesta dos', $joinedPayload);
    }
}
