<?php

namespace Tests\Unit\Support;

use App\Support\AgentCatalog;
use App\Support\ConfigurableAgentRegistry;
use Tests\TestCase;

class ConfigurableAgentRegistryTest extends TestCase
{
    public function test_every_configurable_agent_has_a_registered_runtime_implementation(): void
    {
        // These agents appear in the catalog for model-assignment purposes but are handled
        // by dedicated implementations outside of ConfigurableAgentRegistry.
        // These agents have dedicated implementations and are not routed through ConfigurableAgentRegistry.
        // optimizer también tiene implementación dedicada (inyector semántico), no pasa por ConfigurableAgentRegistry
        $nonConfigurableAgents = ['librarian', 'gatekeeper', 'safety', 'embedding', 'critic', 'optimizer'];

        $catalog = app(AgentCatalog::class)->all();
        $registry = app(ConfigurableAgentRegistry::class);

        foreach ($catalog as $agent) {
            $key = $agent['key'];

            if (in_array($key, $nonConfigurableAgents, true)) {
                continue;
            }

            $definition = $registry->definitionFor($key);

            $this->assertNotNull($definition, "Falta definicion de runtime para el agente [{$key}]");
            $this->assertTrue(class_exists($definition['implementation']), "La clase de runtime para [{$key}] no existe");

            if ($definition['kind'] === 'generic') {
                $this->assertNotNull($definition['prompt_builder'], "Falta prompt builder para el agente generico [{$key}]");
                $this->assertTrue(class_exists($definition['prompt_builder']), "La clase de prompt para [{$key}] no existe");
            }
        }
    }

    public function test_writer_has_visible_prompt_builder_in_registry(): void
    {
        $definition = app(ConfigurableAgentRegistry::class)->definitionFor('writer');

        $this->assertNotNull($definition);
        $this->assertSame(\App\Infrastructure\Ai\Prompts\SimpleSceneWriterPrompt::class, $definition['prompt_builder']);
    }
}
