<?php

namespace App\Providers;

use App\Application\Contracts\CharacterRepository;
use App\Application\Contracts\CharacterRuntimeStatusRepository;
use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\ContinuityQuestStatusRepository;
use App\Application\Contracts\EventEngineRepository;
use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\EmbeddingGateway;
use App\Application\Contracts\AgentGateway;
use App\Application\Agents\SummarizerAgent;
use App\Application\Agents\LibrarianAgent;
use App\Application\Agents\QuestAgent;
use App\Application\Agents\QuestScaffolderAgent;
use App\Application\Agents\SceneWriterAgent;
use App\Application\Agents\ChroniclerAgent;
use App\Application\Contracts\LegacyCanonImporter;
use App\Application\Contracts\LoreRepository;
use App\Application\Services\VectorRetrievalService;
use App\Application\Contracts\LocationRepository;
use App\Application\Contracts\QaLoopRunner;
use App\Application\Contracts\QuestScaffoldingRepository;
use App\Application\Contracts\SceneCacheRepository;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Application\Contracts\VaultContextRepository;
use App\Application\UseCases\ImportLegacyCanonUseCase;
use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Application\UseCases\RefreshSimpleChatMemoryUseCase;
use App\Application\UseCases\CheckoutContinuityCommitUseCase;
use App\Application\UseCases\ApplyCharacterRuntimeStatusUseCase;
use App\Application\UseCases\ApplyQuestProgressDirectiveUseCase;
use App\Application\UseCases\CreateSceneBootstrapUseCase;
use App\Application\UseCases\CreateVaultStarterPackUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\UseCases\CreateContinuityBranchFromCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromTurnUseCase;
use App\Application\UseCases\CreateContinuityBranchUseCase;
use App\Application\UseCases\RewindContinuityToTurnUseCase;
use App\Application\UseCases\SwitchSceneBranchUseCase;
use App\Infrastructure\Ai\Agents\LaravelAgentGateway;
use App\Infrastructure\Ai\Agents\LaravelQaLoopRunner;
use App\Infrastructure\Ai\Agents\SimpleLibrarianAgent;
use App\Infrastructure\Ai\Agents\AiQuestAgent;
use App\Infrastructure\Ai\Agents\AiQuestScaffolderAgent;
use App\Infrastructure\Ai\Agents\SimpleSceneWriterAgent;
use App\Infrastructure\Ai\Agents\ComplexSceneWriterAgent;
use App\Infrastructure\Ai\Agents\ConfiguredChroniclerAgent;
use App\Infrastructure\Ai\Agents\ConfiguredSummarizerAgent;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Infrastructure\Ai\Agents\ArchetypeOptimizerAgent;
use App\Infrastructure\Ai\Agents\OptimizerProfileAgent;
use App\Infrastructure\Ai\Agents\VaultOptimizerAgent;
use App\Infrastructure\Ai\AnthropicChatGateway;
use App\Infrastructure\Ai\ConfiguredAiChatGateway;
use App\Infrastructure\Ai\ConfiguredEmbeddingGateway;
use App\Infrastructure\Ai\OllamaAiGateway;
use App\Infrastructure\Ai\OpenRouterChatGateway;
use App\Infrastructure\Ai\OpenRouterEmbeddingGateway;
use App\Infrastructure\Ai\ThinkingAiChatGateway;
use App\Infrastructure\Logging\LaravelStructuredLogger;
use App\Infrastructure\Persistence\Cache\LaravelSceneCacheRepository;
use App\Infrastructure\Persistence\Eloquent\CachedSceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentCharacterRuntimeStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentContinuityQuestStatusRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentEventEngineRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentQuestScaffoldingRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneContextBuilder;
use App\Infrastructure\Persistence\Eloquent\EloquentLocationRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentSceneRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentVaultContextRepository;
use App\Infrastructure\Persistence\Eloquent\QdrantLoreRepository;
use App\Infrastructure\Persistence\LegacySqlite\LegacyCanonSqliteImporter;
use App\Services\Discord\Contracts\DiscordSignatureValidator;
use App\Services\Discord\Contracts\DiscordWebhookClient;
use App\Services\Discord\ProductionSignatureValidator;
use App\Services\Discord\ProductionWebhookClient;
use App\Support\SimpleChatMemoryManager;
use App\Support\ConfigurableAgentRegistry;
use App\Support\ConfiguredAgentPromptRegistry;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Discord signature validation: Siempre validar usando la clave pública.
        $this->app->bind(DiscordSignatureValidator::class, function () {
            $publicKey = (string) env('DISCORD_PUBLIC_KEY', '');

            if (config('app.env') !== 'production') {
                return new \App\Services\Discord\TestingSignatureValidator($publicKey);
            }

            return new ProductionSignatureValidator($publicKey);
        });

        // Discord webhook follow-up: Siempre usar el cliente de producción.
        $this->app->bind(DiscordWebhookClient::class, function () {
            return new ProductionWebhookClient();
        });

        $this->app->singleton(StructuredLogger::class, LaravelStructuredLogger::class);

        // Loggers con canal dedicado para servicios externos
        $this->app->when([
            \App\Infrastructure\Ai\OpenRouterChatGateway::class,
            \App\Infrastructure\Ai\OpenRouterEmbeddingGateway::class,
        ])->needs(StructuredLogger::class)->give(function () {
            return new LaravelStructuredLogger([], 'openrouter');
        });

        $this->app->when(\App\Application\Services\QdrantService::class)
            ->needs(StructuredLogger::class)
            ->give(function () {
                return new LaravelStructuredLogger([], 'qdrant');
            });
        $this->app->bind(VaultContextRepository::class, EloquentVaultContextRepository::class);
        $this->app->bind(CharacterRepository::class, EloquentCharacterRepository::class);
        $this->app->bind(CharacterRuntimeStatusRepository::class, EloquentCharacterRuntimeStatusRepository::class);
        $this->app->bind(ContinuityRepository::class, EloquentContinuityRepository::class);
        $this->app->bind(ContinuityQuestStatusRepository::class, EloquentContinuityQuestStatusRepository::class);
        $this->app->bind(EventEngineRepository::class, EloquentEventEngineRepository::class);
        $this->app->bind(LocationRepository::class, EloquentLocationRepository::class);
        $this->app->bind(QuestScaffoldingRepository::class, EloquentQuestScaffoldingRepository::class);
        $this->app->bind(LoreRepository::class, QdrantLoreRepository::class);
        $this->app->bind(SceneRepository::class, EloquentSceneRepository::class);
        $this->app->singleton(EloquentSceneContextBuilder::class);
        $this->app->bind(SceneContextBuilder::class, function ($app): CachedSceneContextBuilder {
            /** @var CacheFactory $cache */
            $cache = $app->make(CacheFactory::class);

            return new CachedSceneContextBuilder(
                $app->make(EloquentSceneContextBuilder::class),
                $cache->store(config('cache.default')),
            );
        });
        $this->app->bind(SceneCacheRepository::class, function ($app): LaravelSceneCacheRepository {
            /** @var CacheFactory $cache */
            $cache = $app->make(CacheFactory::class);

            return new LaravelSceneCacheRepository(
                $cache->store(config('cache.default')),
            );
        });
        $this->app->singleton(SimpleChatMemoryManager::class);
        $this->app->singleton(OpenRouterChatGateway::class);
        $this->app->singleton(OpenRouterEmbeddingGateway::class);
        $this->app->bind(EmbeddingGateway::class, \App\Infrastructure\Ai\ConfiguredEmbeddingGateway::class);
        $this->app->singleton(AnthropicChatGateway::class);
        $this->app->singleton(OllamaAiGateway::class);
        $this->app->singleton(ComplexSceneWriterAgent::class);
        $this->app->bind(AiChatGateway::class, function ($app): ConfiguredAiChatGateway {
            return new ConfiguredAiChatGateway(
                $app->make(AnthropicChatGateway::class),
                $app->make(OllamaAiGateway::class),
                $app->make(StructuredLogger::class),
            );
        });
        $this->app->bind(SceneWriterAgent::class, SimpleSceneWriterAgent::class);
        $this->app->bind(QuestAgent::class, AiQuestAgent::class);
        $this->app->bind(LibrarianAgent::class, SimpleLibrarianAgent::class);
        $this->app->bind(QuestScaffolderAgent::class, AiQuestScaffolderAgent::class);
        $this->app->bind(SummarizerAgent::class, ConfiguredSummarizerAgent::class);
        $this->app->bind(ChroniclerAgent::class, ConfiguredChroniclerAgent::class);
        $this->app->bind(QaLoopRunner::class, LaravelQaLoopRunner::class);
        $this->app->bind(AgentGateway::class, function ($app): LaravelAgentGateway {
            return new LaravelAgentGateway(
                $app->make(SimpleSceneWriterAgent::class),
                $app->make(ComplexSceneWriterAgent::class),
            );
        });
        $this->app->singleton(ConfigurableAgentRegistry::class);
        $this->app->singleton(ConfiguredAgentPromptRegistry::class);
        $this->app->bind(LegacyCanonImporter::class, LegacyCanonSqliteImporter::class);
        $this->app->singleton(ImportLegacyCanonUseCase::class);
        $this->app->singleton(GenerateSceneTurnUseCase::class);
        $this->app->singleton(RefreshSimpleChatMemoryUseCase::class);
        $this->app->singleton(ApplyCharacterRuntimeStatusUseCase::class);
        $this->app->singleton(ApplyQuestProgressDirectiveUseCase::class);
        $this->app->singleton(CreateSceneBootstrapUseCase::class);
        $this->app->singleton(CreateVaultStarterPackUseCase::class);
        $this->app->singleton(GenerateContinuityTurnUseCase::class);
        $this->app->singleton(VectorRetrievalService::class);
        $this->app->singleton(CreateContinuityBranchUseCase::class);
        $this->app->singleton(CheckoutContinuityCommitUseCase::class);
        $this->app->singleton(CreateContinuityBranchFromCommitUseCase::class);
        $this->app->singleton(CreateContinuityBranchFromTurnUseCase::class);
        $this->app->singleton(RewindContinuityToTurnUseCase::class);
        $this->app->singleton(SwitchSceneBranchUseCase::class);

        $optimizerAgents = [
            StyleOptimizerAgent::class,
            ContextOptimizerAgent::class,
            ArchetypeOptimizerAgent::class,
            OptimizerProfileAgent::class,
            VaultOptimizerAgent::class,
        ];

        foreach ($optimizerAgents as $agentClass) {
            $this->app->when($agentClass)
                ->needs(AiChatGateway::class)
                ->give(fn ($app) => new ThinkingAiChatGateway($app->make(AiChatGateway::class)));
        }
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            [\SocialiteProviders\Discord\DiscordExtendSocialite::class, 'handle']
        );

        \App\Domains\Matchmaking\Models\Archetype::observe(
            \App\Domains\Matchmaking\Observers\ArchetypeObserver::class
        );
    }
}
