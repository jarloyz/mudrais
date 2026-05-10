<?php

namespace Tests\Feature\Discord;

use App\Application\Services\TagNormalizerService;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Community\Models\Guild;
use App\Infrastructure\Ai\Agents\VaultOptimizerAgent;
use App\Services\Discord\Contracts\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Mockery;

class VaultOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = $this->createMock(\App\Services\Discord\Contracts\DiscordSignatureValidator::class);
        $mock->method('isValid')->willReturn(true);
        $this->app->instance(\App\Services\Discord\Contracts\DiscordSignatureValidator::class, $mock);
    }

    public function test_autocomplete_returns_archetypes()
    {
        Archetype::create([
            'name' => 'Cyberpunk Noir',
            'slug' => 'cyberpunk-noir',
            'qdrant_vector_name' => 'test_vector',
        ]);

        $payload = [
            'type' => 4,
            'data' => [
                'name' => 'create_vault',
                'options' => [
                    [
                        'name' => 'archetype',
                        'value' => 'Cyber',
                        'focused' => true,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/discord/interactions', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'type' => 8,
                'data' => [
                    'choices' => [
                        [
                            'name' => 'Cyberpunk Noir',
                        ]
                    ]
                ]
            ]);
    }

    public function test_create_vault_command_returns_modal()
    {
        $payload = [
            'type' => 2,
            'data' => [
                'name' => 'create_vault',
                'options' => [
                    [
                        'name' => 'archetype',
                        'value' => '123',
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/discord/interactions', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'type' => 9,
                'data' => [
                    'custom_id' => 'create_vault_modal:123:0',
                ]
            ]);
    }

    public function test_modal_submit_dispatches_job()
    {
        Queue::fake();

        $payload = [
            'type' => 5,
            'token' => 'test-token',
            'guild_id' => 'guild-123',
            'member' => ['user' => ['id' => 'user-123']],
            'data' => [
                'custom_id' => 'create_vault_modal:456',
                'components' => [
                    [
                        'type' => 1,
                        'components' => [
                            [
                                'type' => 4,
                                'custom_id' => 'vault_name',
                                'value' => 'Nuevo Vault'
                            ],
                            [
                                'type' => 4,
                                'custom_id' => 'vault_description',
                                'value' => 'Una descripción larga'
                            ],
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/discord/interactions', $payload);

        $response->assertStatus(200)
            ->assertJson(['type' => 5]);

        Queue::assertPushed(\App\Jobs\Discord\ProcessVaultOnboardingJob::class);
    }

    public function test_job_optimizes_vault_and_waits_for_approval()
    {
        $archetype = Archetype::create([
            'name' => 'Fantasía',
            'slug' => 'fantasia',
            'qdrant_vector_name' => 'test_vector',
        ]);

        $guild = Guild::create([
            'name' => 'Test Guild',
            'discord_guild_id' => 'guild-123',
        ]);

        $optimizerAgent = Mockery::mock(VaultOptimizerAgent::class);
        $optimizerAgent->shouldReceive('optimize')
            ->once()
            ->andReturn([
                'name_es' => 'Nuevo Vault Optimizado',
                'name_en' => 'New Vault Optimized',
                'optimized_text_en' => 'A great place.',
                'semantic_tag_query' => 'great place, fantasy realm',
            ]);
        $this->app->instance(VaultOptimizerAgent::class, $optimizerAgent);

        $tagNormalizer = Mockery::mock(TagNormalizerService::class);
        $tagNormalizer->shouldReceive('normalizeBatch')
            ->once()
            ->andReturn([]);
        $this->app->instance(TagNormalizerService::class, $tagNormalizer);

        $webhookClient = Mockery::mock(DiscordWebhookClient::class);
        $webhookClient->shouldReceive('sendFollowUp')
            ->once()
            ->with('test-token', '', Mockery::type('array'), true);

        $this->app->instance(DiscordWebhookClient::class, $webhookClient);

        $job = new \App\Jobs\Discord\ProcessVaultOnboardingJob(
            'test-token',
            'guild-123',
            (string) $archetype->id,
            'Nuevo Vault',
            'Una descripción larga',
            []
        );

        $this->app->call([$job, 'handle']);

        // El test verifica que optimize fue llamado, normalizeBatch fue llamado, y sendFollowUp fue llamado con ephemeral=true a través de los mocks.
        $this->assertTrue(true);
    }
}
