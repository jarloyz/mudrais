<?php

namespace Tests\Unit\Services;

use App\Application\Services\ArchetypeResolverService;
use App\Domains\Matchmaking\Models\Archetype;
use App\Models\Guild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchetypeResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArchetypeResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ArchetypeResolverService();
        $this->seedArchetypes();
    }

    private function seedArchetypes(): void
    {
        Archetype::create(['name' => 'TTRPG Texto', 'qdrant_vector_name' => 'ttrpg_text_v1']);
        Archetype::create(['name' => 'TTRPG Voz',   'qdrant_vector_name' => 'ttrpg_voice_v1']);
    }

    public function test_resolves_archetype_from_known_guild(): void
    {
        $voiceArchetype = Archetype::where('qdrant_vector_name', 'ttrpg_voice_v1')->first();

        $guild = Guild::create(['discord_guild_id' => 'voice_guild']);
        $guild->archetypes()->attach($voiceArchetype->id, ['is_primary' => true]);

        $resolved = $this->service->resolveFromGuild('voice_guild');

        $this->assertEquals('ttrpg_voice_v1', $resolved->qdrant_vector_name);
    }

    public function test_returns_default_archetype_for_unknown_guild(): void
    {
        $resolved = $this->service->resolveFromGuild('unknown_guild_xyz');

        $this->assertEquals('ttrpg_text_v1', $resolved->qdrant_vector_name);
    }

    public function test_resolves_from_guild_model(): void
    {
        $textArchetype = Archetype::where('qdrant_vector_name', 'ttrpg_text_v1')->first();

        $guild = Guild::create(['discord_guild_id' => 'model_guild']);
        $guild->archetypes()->attach($textArchetype->id, ['is_primary' => true]);

        $resolved = $this->service->resolveFromGuildModel($guild);

        $this->assertEquals('ttrpg_text_v1', $resolved->qdrant_vector_name);
    }

    public function test_resolves_primary_archetype_when_guild_has_multiple(): void
    {
        $textArchetype  = Archetype::where('qdrant_vector_name', 'ttrpg_text_v1')->first();
        $voiceArchetype = Archetype::where('qdrant_vector_name', 'ttrpg_voice_v1')->first();

        $guild = Guild::create(['discord_guild_id' => 'multi_archetype_guild']);
        $guild->archetypes()->attach($textArchetype->id,  ['is_primary' => true]);
        $guild->archetypes()->attach($voiceArchetype->id, ['is_primary' => false]);

        $resolved = $this->service->resolveFromGuild('multi_archetype_guild');

        $this->assertEquals('ttrpg_text_v1', $resolved->qdrant_vector_name);
    }
}
