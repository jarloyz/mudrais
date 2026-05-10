<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Domain\Ports\AgentGatewayInterface;
use App\Infrastructure\Agents\GeminiAgentGateway;

class AgentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(AgentGatewayInterface::class, GeminiAgentGateway::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
