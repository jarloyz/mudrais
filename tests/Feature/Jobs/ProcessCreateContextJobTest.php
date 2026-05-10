<?php

namespace Tests\Feature\Jobs;

use App\Domains\Community\Models\Guild;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use App\Jobs\Discord\ProcessCreateContextJob;
use App\Jobs\IndexAvatarJob;
use App\Services\Discord\Contracts\DiscordWebhookClient;
use App\Services\Discord\DiscordApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessCreateContextJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_avatar_and_discord_thread(): void
    {
        Bus::fake([IndexAvatarJob::class]);
        // 1. Preparar datos
        $guild = Guild::create([
            'id'               => (string) Str::uuid(),
            'name'             => 'Test Guild',
            'discord_guild_id' => '123456789',
        ]);

        $archetype = Archetype::create([
            'name'               => 'Test Archetype',
            'qdrant_vector_name' => 'test_archetype',
            'description'        => 'Description',
        ]);

        $vault = Vault::create([
            'id'                         => '111222333', // Discord channel ID
            'name'                       => 'Test Vault',
            'description'                => 'Vault Description',
            'archetype_id'               => $archetype->id,
            'guild_id'                   => $guild->id,
            'status'                     => 'active',
            'is_public'                  => true,
            'discord_context_channel_id' => '444555666', // Forum channel ID
        ]);

        $entityType = ArchetypeEntityType::create([
            'archetype_id' => $archetype->id,
            'entity'       => 'avatar',
            'type_key'     => 'personaje',
            'type_label'   => 'Personaje',
            'is_active'    => true,
        ]);

        $profile = PlayerArchetypeProfile::create([
            'discord_user_id' => '999888777',
            'archetype_id'    => $archetype->id,
            'positive_prefs'  => [],
        ]);

        // 2. Mocks
        $mockDiscordApi = $this->createMock(DiscordApiService::class);
        $mockDiscordApi->expects($this->once())
            ->method('createThread')
            ->with('444555666', 'Kaelen', 11, $this->isType('array'))
            ->willReturn(['id' => '777888999']);

        $mockWebhookClient = $this->createMock(DiscordWebhookClient::class);
        $mockWebhookClient->expects($this->once())
            ->method('sendFollowUp')
            ->with(
                'test_token',
                '',
                $this->callback(function ($extra) {
                    return isset($extra['embeds'][0]['fields']) &&
                           collect($extra['embeds'][0]['fields'])->contains('name', 'Hilo');
                }),
                true
            );

        $this->app->instance(DiscordWebhookClient::class, $mockWebhookClient);

        // 3. Ejecutar Job
        $job = new ProcessCreateContextJob(
            'test_token',
            '999888777',
            $vault->id,
            $entityType->id,
            'Kaelen',
            ['bio' => 'A brave warrior']
        );

        $job->handle($mockDiscordApi);

        // 4. Verificaciones
        $this->assertDatabaseHas('avatars', [
            'name'              => 'Kaelen',
            'vault_id'          => $vault->id,
            'discord_thread_id' => '777888999',
        ]);

        $avatar = Avatar::where('name', 'Kaelen')->first();
        $this->assertEquals(['bio' => 'A brave warrior'], $avatar->content_raw);

        Bus::assertDispatched(IndexAvatarJob::class, function ($job) use ($avatar) {
            return $job->avatarId === $avatar->id;
        });
    }

    public function test_it_falls_back_to_resolving_channel_by_name_if_not_in_db(): void
    {
        Bus::fake([IndexAvatarJob::class]);
        // 1. Preparar datos (vault SIN discord_context_channel_id)
        $guild = Guild::create([
            'id'               => (string) Str::uuid(),
            'name'             => 'Test Guild',
            'discord_guild_id' => '123456789',
        ]);

        $archetype = Archetype::create([
            'name'               => 'Test Archetype',
            'qdrant_vector_name' => 'test_archetype',
            'description'        => 'Description',
        ]);

        $vault = Vault::create([
            'id'           => '111222333',
            'name'         => 'Test Vault',
            'archetype_id' => $archetype->id,
            'guild_id'     => $guild->id,
            'status'       => 'active',
        ]);

        $entityType = ArchetypeEntityType::create([
            'archetype_id' => $archetype->id,
            'entity'       => 'avatar',
            'type_key'     => 'personaje',
            'type_label'   => 'Personaje',
            'is_active'    => true,
        ]);

        $profile = PlayerArchetypeProfile::create([
            'discord_user_id' => '999888777',
            'archetype_id'    => $archetype->id,
            'positive_prefs'  => [],
        ]);

        // 2. Mocks
        $mockDiscordApi = $this->createMock(DiscordApiService::class);

        // Mock getGuildChannels para el fallback
        $mockDiscordApi->expects($this->once())
            ->method('getGuildChannels')
            ->with('123456789')
            ->willReturn([
                ['id' => '555666', 'name' => 'test-vault-context', 'type' => 15]
            ]);

        $mockDiscordApi->expects($this->once())
            ->method('createThread')
            ->with('555666', 'Kaelen', 11, $this->isType('array'))
            ->willReturn(['id' => 'thread_123']);

        $this->app->instance(DiscordWebhookClient::class, $this->createMock(DiscordWebhookClient::class));

        // 3. Ejecutar Job
        $job = new ProcessCreateContextJob('token', '999888777', $vault->id, $entityType->id, 'Kaelen');
        $job->handle($mockDiscordApi);

        // 4. Verificaciones
        $this->assertDatabaseHas('avatars', ['discord_thread_id' => 'thread_123']);

        // Verificar que el vault se actualizó con el ID encontrado
        $this->assertDatabaseHas('vaults', [
            'id'                         => $vault->id,
            'discord_context_channel_id' => '555666',
        ]);

        Bus::assertDispatched(IndexAvatarJob::class);
    }
}
