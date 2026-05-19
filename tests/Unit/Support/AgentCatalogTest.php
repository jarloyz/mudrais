<?php

namespace Tests\Unit\Support;

use App\Support\AgentCatalog;
use Tests\TestCase;

class AgentCatalogTest extends TestCase
{
    public function test_catalog_contains_the_canonical_agents(): void
    {
        $catalog = app(AgentCatalog::class)->all();
        $keys = array_column($catalog, 'key');

        $this->assertContains('gatekeeper', $keys);
        $this->assertContains('safety', $keys);
        $this->assertContains('embedding', $keys);
        $this->assertContains('librarian', $keys);
        $this->assertContains('writer', $keys);
        $this->assertContains('critic', $keys);
        $this->assertContains('optimizer', $keys);
        $this->assertContains('interviewer', $keys);
        $this->assertCount(8, $catalog);
    }

    public function test_catalog_entries_have_required_fields(): void
    {
        $catalog = app(AgentCatalog::class)->all();

        foreach ($catalog as $agent) {
            $this->assertArrayHasKey('key', $agent);
            $this->assertArrayHasKey('label', $agent);
            $this->assertArrayHasKey('model', $agent);
            $this->assertArrayHasKey('enabled', $agent);
            $this->assertArrayHasKey('section', $agent);
            $this->assertArrayHasKey('description', $agent);
        }
    }

    public function test_sections_are_correctly_assigned(): void
    {
        $catalog = app(AgentCatalog::class)->all();
        $byKey = array_column($catalog, null, 'key');

        $this->assertSame('Ingesta', $byKey['gatekeeper']['section']);
        $this->assertSame('Ingesta', $byKey['safety']['section']);
        $this->assertSame('Ingesta', $byKey['embedding']['section']);
        $this->assertSame('Memoria', $byKey['librarian']['section']);
        $this->assertSame('Narrativa', $byKey['writer']['section']);
        $this->assertSame('Narrativa', $byKey['critic']['section']);
    }

    public function test_model_map_returns_six_entries(): void
    {
        $map = app(AgentCatalog::class)->modelMap();

        $this->assertArrayHasKey('writer', $map);
        $this->assertArrayHasKey('critic', $map);
        $this->assertArrayHasKey('safety', $map);
        $this->assertArrayHasKey('optimizer', $map);
        $this->assertCount(8, $map);
    }
}
