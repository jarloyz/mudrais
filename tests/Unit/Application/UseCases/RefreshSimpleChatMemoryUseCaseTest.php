<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\Agents\SummarizerAgent;
use App\Application\UseCases\RefreshSimpleChatMemoryUseCase;
use App\Domain\Catalog\Vault;
use App\Domain\Scene\Activity;
use App\Infrastructure\Persistence\Cache\FilesystemSceneCacheRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Support\SimpleChatMemoryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\ArrayStructuredLogger;
use Tests\TestCase;

class RefreshSimpleChatMemoryUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_refreshes_simple_chat_memory_and_history_summary(): void
    {
        config()->set('historia.cache.root', sys_get_temp_dir().'/historia_cache_'.uniqid('', true));
        config()->set('historia.simple_memory.batch_message_count', 4);

        $vaultRepository = new EloquentVaultContextRepository();
        $vaultRepository->saveVault(new Vault('vault_refresh', 'Vault Refresh'));

        $sceneRepository = new EloquentSceneRepository();
        $sceneRepository->save(Activity::fromArray([
            'id' => 'scene_refresh',
            'vaultId' => 'vault_refresh',
            'title' => 'Refresh',
            'objective' => 'Refrescar memoria',
            'draft' => 'Inicio estable de la escena.',
        ]));

        $cacheRepository = new FilesystemSceneCacheRepository(new Filesystem());
        $summarizerState = ['calls' => 0];
        $summarizer = new class($summarizerState) implements SummarizerAgent
        {
            /**
             * @param array{calls:int} $state
             */
            public function __construct(private array &$state)
            {
            }

            public function summarizeIncremental(string $sceneId, string $existingSummary, array $messages, ?string $userId = null): string
            {
                $this->state['calls']++;

                return "- Ana y el usuario ya tuvieron dos intercambios.\n- La escena mantiene continuidad inmediata.";
            }
        };

        $useCase = new RefreshSimpleChatMemoryUseCase(
            sceneRepository: $sceneRepository,
            sceneCacheRepository: $cacheRepository,
            simpleChatMemoryManager: new SimpleChatMemoryManager($cacheRepository),
            summarizerAgent: $summarizer,
            logger: new ArrayStructuredLogger(),
        );

        $useCase->execute('scene_refresh', 'Primer mensaje del usuario.', 'Primera respuesta del asistente.');
        $useCase->execute('scene_refresh', 'Segundo mensaje del usuario.', 'Segunda respuesta del asistente.');

        $memory = $cacheRepository->get('simple_chat_memory', 'scene_refresh');
        $history = $cacheRepository->get('history_summary', 'scene_refresh');

        $this->assertIsArray($memory);
        $this->assertSame('Inicio estable de la escena.', $memory['scene_opening']);
        $this->assertCount(4, $memory['recent_messages']);
        $this->assertSame([], $memory['unsummarized_messages']);
        $this->assertSame(1, $summarizerState['calls']);
        $this->assertStringContainsString('dos intercambios', $memory['rolling_summary']);
        $this->assertIsArray($history);
        $this->assertSame($memory['rolling_summary'], $history['summary']);
    }
}
