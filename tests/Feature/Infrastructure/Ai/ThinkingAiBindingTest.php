<?php

namespace Tests\Feature\Infrastructure\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\ArchetypeOptimizerAgent;
use App\Infrastructure\Ai\Agents\ContextOptimizerAgent;
use App\Infrastructure\Ai\Agents\OptimizerProfileAgent;
use App\Infrastructure\Ai\Agents\StyleOptimizerAgent;
use App\Infrastructure\Ai\Agents\VaultOptimizerAgent;
use App\Infrastructure\Ai\ThinkingAiChatGateway;
use Tests\TestCase;

class ThinkingAiBindingTest extends TestCase
{
    public function test_optimizer_agents_receive_thinking_decorator(): void
    {
        $agents = [
            ArchetypeOptimizerAgent::class,
            ContextOptimizerAgent::class,
            OptimizerProfileAgent::class,
            StyleOptimizerAgent::class,
            VaultOptimizerAgent::class,
        ];

        foreach ($agents as $agentClass) {
            $agent = app($agentClass);

            // Usamos reflexión para acceder a la propiedad privada 'gateway'
            $reflection = new \ReflectionClass($agent);
            $property = $reflection->getProperty('gateway');
            $property->setAccessible(true);
            $gateway = $property->getValue($agent);

            $this->assertInstanceOf(
                ThinkingAiChatGateway::class,
                $gateway,
                "El agente $agentClass debería tener un ThinkingAiChatGateway"
            );
        }
    }
}
