<?php

namespace Tests\Unit\Services;

use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Domains\Matchmaking\Services\EntityTypePromptBuilderService;
use App\Domains\Matchmaking\Models\ArchetypePrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityTypePromptBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntityTypePromptBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityTypePromptBuilderService();
    }

    public function test_extract_soft_fields_filtra_por_storage_mode(): void
    {
        $archetype = Archetype::create(['name' => 'Test', 'description' => 'Test', 'status' => 'active', 'qdrant_vector_name' => 'test_vector']);
        $entityType = ArchetypeEntityType::create([
            'archetype_id' => $archetype->id,
            'entity' => 'activity',
            'type_key' => 'test',
            'type_label' => 'test',
        ]);

        ArchetypeMutator::create([
            'archetype_id'              => $archetype->id,
            'archetype_entity_type_id'  => $entityType->id,
            'context'                   => 'activities_vibe',
            'field_key'                 => 'field_raw',
            'field_label'               => 'Field Raw',
            'storage_mode'              => MutatorStorageMode::RAW,
            'field_type'                => 'text',
        ]);

        ArchetypeMutator::create([
            'archetype_id'              => $archetype->id,
            'archetype_entity_type_id'  => $entityType->id,
            'context'                   => 'activities_vibe',
            'field_key'                 => 'field_sem',
            'field_label'               => 'Field Semantic',
            'storage_mode'              => MutatorStorageMode::SEMANTIC,
            'field_type'                => 'text',
        ]);

        ArchetypeMutator::create([
            'archetype_id'              => $archetype->id,
            'archetype_entity_type_id'  => $entityType->id,
            'context'                   => 'activities_vibe',
            'field_key'                 => 'field_both',
            'field_label'               => 'Field Both',
            'storage_mode'              => MutatorStorageMode::BOTH,
            'field_type'                => 'text',
        ]);

        $contentRaw = [
            'field_raw' => 'raw data',
            'field_sem' => 'sem data',
            'field_both' => 'both data',
        ];

        $extracted = $this->service->extractSoftFields($entityType, $contentRaw);

        $this->assertArrayNotHasKey('Field Raw', $extracted);
        $this->assertArrayHasKey('Field Semantic', $extracted);
        $this->assertArrayHasKey('Field Both', $extracted);

        $this->assertEquals('sem data', $extracted['Field Semantic']);
        $this->assertEquals('both data', $extracted['Field Both']);
    }

    public function test_build_prompt_inyecta_variables(): void
    {
        $archetype = Archetype::create(['name' => 'Test', 'description' => 'Test', 'status' => 'active', 'qdrant_vector_name' => 'test_vector']);

        ArchetypePrompt::create([
            'archetype_id' => $archetype->id,
            'agent_type' => 'optimizer',
            'system_prompt' => 'BE VERY CONCISE',
        ]);

        $entityType = ArchetypeEntityType::create([
            'archetype_id' => $archetype->id,
            'entity' => 'activity',
            'type_key' => 'test',
            'type_label' => 'test',
            'system_prompt' => 'Data: {user_soft_data_json} Rules: {archetype_prompt_injection}'
        ]);

        $softFields = ['Some Field' => 'Some Value'];

        $prompt = $this->service->buildPrompt($entityType, $softFields);

        $expectedJson = json_encode($softFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $this->assertStringContainsString('Data: ' . $expectedJson, $prompt);
        $this->assertStringContainsString('Rules: BE VERY CONCISE', $prompt);
    }

    public function test_build_prompt_retorna_vacio_si_system_prompt_es_null(): void
    {
        $archetype = Archetype::create(['name' => 'Test', 'description' => 'Test', 'status' => 'active', 'qdrant_vector_name' => 'test_vector']);
        $entityType = ArchetypeEntityType::create([
            'archetype_id' => $archetype->id,
            'entity' => 'activity',
            'type_key' => 'test',
            'type_label' => 'test',
            'system_prompt' => null
        ]);

        $prompt = $this->service->buildPrompt($entityType, ['field' => 'value']);
        $this->assertEquals('', $prompt);
    }
}
