<?php

namespace App\Domains\Intelligence;

use App\Domains\Intelligence\Contracts\AiChatGatewayInterface;
use App\Domains\Intelligence\Contracts\EmbeddingGatewayInterface;
use App\Infrastructure\Ai\ConfiguredAiChatGateway;
use App\Infrastructure\Ai\OpenRouterEmbeddingGateway;
use Illuminate\Support\ServiceProvider;

class IntelligenceServiceProvider extends ServiceProvider
{
    

    public function register(): void
    {
        // Mapear interfaces del dominio Intelligence a las implementaciones de infraestructura.
        // ConfiguredAiChatGateway ya está registrado como singleton en AppServiceProvider.
        $this->app->bind(
            AiChatGatewayInterface::class,
            fn ($app) => $app->make(ConfiguredAiChatGateway::class),
        );

        $this->app->bind(
            EmbeddingGatewayInterface::class,
            fn ($app) => $app->make(OpenRouterEmbeddingGateway::class),
        );
    }

    public function boot(): void
    {
        //
    }
}
