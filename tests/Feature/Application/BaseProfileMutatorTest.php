<?php

namespace Tests\Feature\Application;

use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Infrastructure\Ai\Agents\OptimizerProfileAgent;
use App\Infrastructure\Ai\Agents\ProfileTranslatorAgent;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use Database\Seeders\ArchetypeSeeder;
use Database\Seeders\BaseProfileMutatorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Mockery;

class BaseProfileMutatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_4_base_mutators(): void
    {
        $this->seed(ArchetypeSeeder::class);
        $this->seed(BaseProfileMutatorSeeder::class);

        $archetypes = Archetype::all();
        $required = ['red_lines', 'yellow_lines', 'preferences', 'style'];

        foreach ($archetypes as $archetype) {
            foreach ($required as $key) {
                $this->assertDatabaseHas('archetype_mutators', [
                    'archetype_id' => $archetype->id,
                    'field_key'    => $key,
                    'context'      => 'registration',
                ]);
            }

            // Verificar que no existen las keys antiguas
            $this->assertDatabaseMissing('archetype_mutators', [
                'archetype_id' => $archetype->id,
                'field_key'    => 'engagement_mode',
            ]);
        }
    }

    public function test_service_returns_paginated_mutators(): void
    {
        $archetype = Archetype::create([
            'name'               => 'Test Arquetipo',
            'qdrant_vector_name' => 'test_v1',
        ]);

        // Los 4 base ya se crearon por el ArchetypeObserver
        $this->assertCount(4, $archetype->mutators);

        // Crear 4 mutadores extra (total 8)
        for ($i = 1; $i <= 4; $i++) {
            ArchetypeMutator::create([
                'archetype_id' => $archetype->id,
                'context'      => 'registration',
                'field_key'    => "extra_$i",
                'field_label'  => "Extra $i",
                'field_type'   => 'text_short',
                'storage_mode' => MutatorStorageMode::RAW,
                'is_required'  => false,
                'sort_order'   => 10 + $i,
            ]);
        }

        $service = app(ArchetypeMutatorService::class);
        $pages = $service->buildStep2ModalPages($archetype->id);

        // 4 base + 4 extra = 8 componentes.
        // Página 1: 5 componentes. Página 2: 3 componentes.
        $this->assertCount(2, $pages);
        $this->assertCount(5, $pages[0]);
        $this->assertCount(3, $pages[1]);

        // Verificar orden de la página 1
        $this->assertEquals('red_lines', $pages[0][0]['component']['custom_id']);
        $this->assertEquals('style', $pages[0][3]['component']['custom_id']);
    }

    public function test_job_processes_all_fields_correctly(): void
    {
        Bus::fake();

        $archetype = Archetype::create([
            'name'               => 'TTRPG Texto',
            'qdrant_vector_name' => 'ttrpg_text_v1',
        ]);

        $this->seed(BaseProfileMutatorSeeder::class);

        Player::create([
            'discord_id'  => '123456789',
            'name'        => 'Test Player',
            'username'    => 'testplayer',
            'nationality' => 'Spanish',
        ]);

        // Mock Translator
        $translator = Mockery::mock(ProfileTranslatorAgent::class);
        $translator->shouldReceive('translate')
            ->once()
            ->andReturn([
                'red_lines'    => ['Gore'],
                'yellow_lines' => ['Insects'],
                'affinities'   => ['Drama', 'Horror'],
            ]);
        $this->app->instance(ProfileTranslatorAgent::class, $translator);

        // Mock Optimizer para verificar que recibe todos los campos semánticos
        $optimizer = Mockery::mock(OptimizerProfileAgent::class);
        $optimizer->shouldReceive('optimize')
            ->once()
            ->with(
                Mockery::on(function ($data) {
                    return isset($data['preferences'])
                        && isset($data['style'])
                        && $data['preferences'] === 'Drama, Horror'
                        && $data['style'] === '3rd person';
                }),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn([
                'optimized_text'     => 'Optimized Bio',
                'semantic_tag_query' => 'Horror Drama',
            ]);

        $this->app->instance(OptimizerProfileAgent::class, $optimizer);

        $job = new ProcessRegistroStep2Job(
            discordId: '123456789',
            data: [
                'red_lines'    => 'Gore',
                'yellow_lines' => 'Insects',
                'preferences'  => 'Drama, Horror',
                'style'        => '3rd person',
            ],
            token: 'test-token',
            guildId: 'guild-123'
        );

        $this->app->call([$job, 'handle']);

        $profile = PlayerArchetypeProfile::where('discord_user_id', '123456789')->first();

        $this->assertEquals(['Gore'], $profile->red_lines);
        $this->assertEquals(['Insects'], $profile->yellow_lines);
        $this->assertEquals(['Drama', 'Horror'], $profile->positive_prefs);
        $this->assertEquals('3rd person', $profile->raw_profile);
    }
}
