<?php

namespace Tests\Unit\Matchmaking;

use App\Application\Services\QdrantService;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Infrastructure\QdrantHubMatchmakingRepository;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class QdrantHubMatchmakingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lfg_uses_player_style_from_postgres_not_embedding()
    {
        $archetype = Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player = Player::create(['discord_id' => 'user-1', 'username' => 'test1']);
        $profile = PlayerArchetypeProfile::create([
            'player_id' => $player->id,
            'discord_user_id' => 'user-1',
            'archetype_id' => $archetype->id,
            'player_style_vector' => [0.1, 0.2],
            'positive_prefs' => [],
            'red_lines' => [],
            'yellow_lines' => [],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            [0.1, 0.2],
            Mockery::any(),
            Mockery::any(),
            10
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findActivitiesForPlayer('user-1', $archetype->id, 'guild-1');
        $this->assertTrue(true); // Assertion happens in Mockery
    }

    public function test_lfg_filters_by_status_recruiting_and_guild()
    {
        $archetype = Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player = Player::create(['discord_id' => 'user-2', 'username' => 'test2']);
        $profile = PlayerArchetypeProfile::create([
            'player_id' => $player->id,
            'discord_user_id' => 'user-2',
            'archetype_id' => $archetype->id,
            'player_style_vector' => [0.1, 0.2],
            'positive_prefs' => [],
            'red_lines' => [],
            'yellow_lines' => [],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            [0.1, 0.2],
            Mockery::on(function ($must) {
                return in_array(['key' => 'entity_type', 'match' => ['value' => 'activity']], $must)
                    && in_array(['key' => 'status', 'match' => ['value' => 1]], $must)
                    && in_array(['key' => 'guild_ids', 'match' => ['value' => 'guild-2']], $must);
            }),
            Mockery::any(),
            10
        )->once()->andReturn([
            ['id' => 'activity-abc', 'score' => 0.9, 'payload' => ['entity_type' => 'activity']]
        ]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $results = $repo->findActivitiesForPlayer('user-2', $archetype->id, 'guild-2');
        $this->assertCount(1, $results);
        $this->assertEquals('activity-abc', $results[0]->qdrantId);
    }

    public function test_lfp_filters_by_entity_type_avatar_and_is_lfg()
    {
        $vault = Vault::create(['id' => 'vault-123', 'name' => 'Vault 123']);
        $archetype = Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player = Player::create(['discord_id' => 'user-test', 'username' => 'test']);
        $profile = PlayerArchetypeProfile::create([
            'player_id' => $player->id,
            'discord_user_id' => 'user-test',
            'archetype_id' => $archetype->id,
            'positive_prefs' => [],
            'red_lines' => [],
            'yellow_lines' => [],
        ]);

        $activity = Activity::create([
            'id' => 'act-123',
            'vault_id' => $vault->id,
            'creator_profile_id' => $profile->id,
            'activity_hub_qdrant_id' => 'qdrant-act-123',
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('getHubVector')->with('qdrant-act-123', 'activity_vibe')->once()->andReturn([0.3, 0.4]);

        $qdrant->shouldReceive('searchHub')->with(
            'avatar_context',
            [0.3, 0.4],
            Mockery::on(function ($must) {
                return in_array(['key' => 'entity_type', 'match' => ['value' => 'avatar']], $must)
                    && in_array(['key' => 'is_lfg', 'match' => ['value' => true]], $must)
                    && in_array(['key' => 'guild_ids', 'match' => ['value' => 'guild-3']], $must);
            }),
            Mockery::any(),
            10
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findAvatarsForActivity('act-123', 'guild-3');
    }

    public function test_p2p_excludes_requester_red_lines_in_must_not()
    {
        $archetype = Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player = Player::create(['discord_id' => 'user-3', 'username' => 'test3']);
        $profile = PlayerArchetypeProfile::create([
            'player_id' => $player->id,
            'discord_user_id' => 'user-3',
            'archetype_id' => $archetype->id,
            'player_style_vector' => [0.5, 0.6],
            'positive_prefs' => [],
            'yellow_lines' => [],
            'red_lines' => [42, 99], // Using dummy tag IDs as per FASE 2 logic
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            [0.5, 0.6],
            Mockery::any(),
            Mockery::on(function ($mustNot) {
                // Should exclude red lines
                return in_array(['key' => 'red_lines', 'match' => ['value' => 42]], $mustNot)
                    && in_array(['key' => 'red_lines', 'match' => ['value' => 99]], $mustNot);
            }),
            10
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findCompatiblePlayersP2P('user-3', $archetype->id, 'guild-4');
    }

    public function test_findProfilesForActivity_uses_ctx1_avatar_context_vector()
    {
        $archetype = Archetype::create(['name' => 'Test', 'qdrant_vector_name' => 'test']);
        $player    = Player::create(['discord_id' => 'inbound-1', 'username' => 'ib1']);
        $vault     = Vault::create(['name' => 'Vault IB']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'inbound-1',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);
        $avatar = Avatar::create([
            'name'                  => 'ctx1-avatar',
            'vault_id'              => $vault->id,
            'avatar_context_vector' => [0.7, 0.8],
        ]);
        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['ctx1_id' => $avatar->id],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        // Must use the ctx1 avatar_context_vector as the query vector against player_style space.
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            [0.7, 0.8],
            Mockery::any(),
            Mockery::any(),
            10
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findProfilesForActivity($activity, 'guild-ib');
        $this->assertTrue(true);
    }

    public function test_findProfilesForActivity_adds_is_available_filter_when_requested()
    {
        $archetype = Archetype::create(['name' => 'Test2', 'qdrant_vector_name' => 'test2']);
        $player    = Player::create(['discord_id' => 'inbound-2', 'username' => 'ib2']);
        $vault     = Vault::create(['name' => 'Vault IB2']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'inbound-2',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);
        $avatar = Avatar::create([
            'name'                  => 'ctx1-avail',
            'vault_id'              => $vault->id,
            'avatar_context_vector' => [0.3, 0.4],
        ]);
        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['ctx1_id' => $avatar->id],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            Mockery::any(),
            Mockery::on(function ($must) {
                return in_array(['key' => 'is_available', 'match' => ['value' => true]], $must);
            }),
            Mockery::any(),
            Mockery::any()
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findProfilesForActivity($activity, 'guild-ib2', filterAvailable: true);
        $this->assertTrue(true);
    }

    public function test_findProfilesForTeamActivity_groups_results_by_slot_id()
    {
        $archetype = Archetype::create(['name' => 'Team', 'qdrant_vector_name' => 'team']);
        $player    = Player::create(['discord_id' => 'team-owner', 'username' => 'owner']);
        $vault     = Vault::create(['name' => 'Vault Team']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'team-owner',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $parent = Activity::create([
            'vault_id'       => $vault->id,
            'title'          => 'Parent Activity',
            'required_slots' => 2,
        ]);

        $avatar1 = Avatar::create(['name' => 'slot1-ctx1', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.1, 0.2]]);
        $avatar2 = Avatar::create(['name' => 'slot2-ctx1', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.3, 0.4]]);

        $slot1 = Activity::create([
            'vault_id'          => $vault->id,
            'title'             => 'Slot Backend',
            'creator_profile_id'=> $profile->id,
            'parent_activity_id'=> $parent->id,
            'content_raw'       => ['ctx1_id' => $avatar1->id],
        ]);
        $slot2 = Activity::create([
            'vault_id'          => $vault->id,
            'title'             => 'Slot Frontend',
            'creator_profile_id'=> $profile->id,
            'parent_activity_id'=> $parent->id,
            'content_raw'       => ['ctx1_id' => $avatar2->id],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        // Slot 1 returns one candidate, slot 2 returns empty.
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.1, 0.2], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([['id' => 'prof-aaa', 'score' => 0.9, 'payload' => ['entity_type' => 'player_profile']]]);
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.3, 0.4], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([]);

        $repo    = new QdrantHubMatchmakingRepository($qdrant);
        $results = $repo->findProfilesForTeamActivity($parent, 'guild-team');

        $this->assertArrayHasKey($slot1->id, $results);
        $this->assertArrayHasKey($slot2->id, $results);
        $this->assertEquals('Slot Backend', $results[$slot1->id]['slot_title']);
        $this->assertCount(1, $results[$slot1->id]['candidates']);
        $this->assertCount(0, $results[$slot2->id]['candidates']);
    }

    public function test_findActivitiesForPlayer_excludes_inbound_activities_via_must_not()
    {
        $archetype = Archetype::create(['name' => 'Excl', 'qdrant_vector_name' => 'excl']);
        $player    = Player::create(['discord_id' => 'user-excl', 'username' => 'excl']);
        PlayerArchetypeProfile::create([
            'player_id'           => $player->id,
            'discord_user_id'     => 'user-excl',
            'archetype_id'        => $archetype->id,
            'player_style_vector' => [0.5, 0.5],
            'positive_prefs'      => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            [0.5, 0.5],
            Mockery::any(),
            Mockery::on(function ($mustNot) {
                return in_array(
                    ['key' => 'search_direction', 'match' => ['value' => 'inbound']],
                    $mustNot
                );
            }),
            10
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findActivitiesForPlayer('user-excl', $archetype->id, 'guild-excl');
        $this->assertTrue(true);
    }

    public function test_player_archetype_profile_is_available_defaults_to_true()
    {
        $archetype = Archetype::create(['name' => 'Avail', 'qdrant_vector_name' => 'avail']);
        $player    = Player::create(['discord_id' => 'user-avail', 'username' => 'avail']);

        $profile = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'user-avail',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $this->assertTrue($profile->fresh()->is_available);
    }

    // ── findProfilesForProjectActivity ──────────────────────────────────────────

    public function test_findProfilesForProjectActivity_searches_once_per_role()
    {
        $archetype = Archetype::create(['name' => 'Proj', 'qdrant_vector_name' => 'proj']);
        $player    = Player::create(['discord_id' => 'proj-owner', 'username' => 'powner']);
        $vault     = Vault::create(['name' => 'Vault Proj']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'proj-owner',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $role1 = Avatar::create(['name' => 'Backend Dev', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.1, 0.2]]);
        $role2 = Avatar::create(['name' => 'Frontend Dev','vault_id' => $vault->id, 'avatar_context_vector' => [0.3, 0.4]]);

        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['roles' => [$role1->id, $role2->id]],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        // searchHub debe llamarse exactamente una vez por role.
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.1, 0.2], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([]);
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.3, 0.4], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([]);

        $repo    = new QdrantHubMatchmakingRepository($qdrant);
        $results = $repo->findProfilesForProjectActivity($activity, 'guild-proj');

        $this->assertArrayHasKey($role1->id, $results);
        $this->assertArrayHasKey($role2->id, $results);
    }

    public function test_findProfilesForProjectActivity_deduplicates_across_roles()
    {
        $archetype = Archetype::create(['name' => 'ProjDedup', 'qdrant_vector_name' => 'projd']);
        $player    = Player::create(['discord_id' => 'dedup-owner', 'username' => 'downer']);
        $vault     = Vault::create(['name' => 'Vault Dedup']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'dedup-owner',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $role1 = Avatar::create(['name' => 'Role A', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.1, 0.2]]);
        $role2 = Avatar::create(['name' => 'Role B', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.3, 0.4]]);

        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['roles' => [$role1->id, $role2->id]],
        ]);

        $sharedPoint = ['id' => 'shared-qdrant', 'score' => 0.9, 'payload' => ['entity_type' => 'player_profile']];
        $uniquePoint = ['id' => 'unique-qdrant', 'score' => 0.8, 'payload' => ['entity_type' => 'player_profile']];

        $qdrant = Mockery::mock(QdrantService::class);
        // Role 1 devuelve shared + unique; role 2 devuelve solo shared (duplicado).
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.1, 0.2], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([$sharedPoint, $uniquePoint]);
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.3, 0.4], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([$sharedPoint]);

        $repo    = new QdrantHubMatchmakingRepository($qdrant);
        $results = $repo->findProfilesForProjectActivity($activity, 'guild-dd');

        // Role 1: 2 candidatos (shared + unique).
        $this->assertCount(2, $results[$role1->id]['candidates']);
        // Role 2: 0 candidatos (shared ya visto en role 1).
        $this->assertCount(0, $results[$role2->id]['candidates']);
    }

    public function test_findProfilesForProjectActivity_blends_context_vector()
    {
        $archetype = Archetype::create(['name' => 'ProjBlend', 'qdrant_vector_name' => 'projb']);
        $player    = Player::create(['discord_id' => 'blend-owner', 'username' => 'bowner']);
        $vault     = Vault::create(['name' => 'Vault Blend']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'blend-owner',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $role    = Avatar::create(['name' => 'Role', 'vault_id' => $vault->id, 'avatar_context_vector' => [1.0, 0.0]]);
        $context = Avatar::create(['name' => 'Ctx',  'vault_id' => $vault->id, 'avatar_context_vector' => [0.0, 1.0]]);

        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['roles' => [$role->id], 'contexts' => [$context->id]],
        ]);

        // Blend esperado: 0.7 × [1.0, 0.0] + 0.3 × [0.0, 1.0] = [0.7, 0.3]
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with(
            'player_style',
            Mockery::on(fn($v) => abs($v[0] - 0.7) < 0.001 && abs($v[1] - 0.3) < 0.001),
            Mockery::any(),
            Mockery::any(),
            10
        )->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findProfilesForProjectActivity($activity, 'guild-blend');
        $this->assertTrue(true);
    }

    public function test_findProfilesForProjectActivity_skips_roles_without_vector()
    {
        $archetype = Archetype::create(['name' => 'ProjSkip', 'qdrant_vector_name' => 'projs']);
        $player    = Player::create(['discord_id' => 'skip-owner', 'username' => 'sowner']);
        $vault     = Vault::create(['name' => 'Vault Skip']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'skip-owner',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $roleWithVector    = Avatar::create(['name' => 'Has Vector', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.5, 0.5]]);
        $roleWithoutVector = Avatar::create(['name' => 'No Vector',  'vault_id' => $vault->id]); // sin vector

        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['roles' => [$roleWithVector->id, $roleWithoutVector->id]],
        ]);

        $qdrant = Mockery::mock(QdrantService::class);
        // Solo debe llamar searchHub para el role con vector.
        $qdrant->shouldReceive('searchHub')->once()->andReturn([]);

        $repo    = new QdrantHubMatchmakingRepository($qdrant);
        $results = $repo->findProfilesForProjectActivity($activity, 'guild-skip');

        $this->assertArrayHasKey($roleWithVector->id, $results);
        $this->assertArrayNotHasKey($roleWithoutVector->id, $results);
    }

    public function test_findProfilesForProjectActivity_no_blend_without_context()
    {
        $archetype = Archetype::create(['name' => 'ProjNoCtx', 'qdrant_vector_name' => 'projnc']);
        $player    = Player::create(['discord_id' => 'noctx-owner', 'username' => 'ncowner']);
        $vault     = Vault::create(['name' => 'Vault NoCtx']);
        $profile   = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id'=> 'noctx-owner',
            'archetype_id'   => $archetype->id,
            'positive_prefs' => [], 'red_lines' => [], 'yellow_lines' => [],
        ]);

        $role = Avatar::create(['name' => 'Pure Role', 'vault_id' => $vault->id, 'avatar_context_vector' => [0.6, 0.8]]);

        $activity = Activity::create([
            'vault_id'          => $vault->id,
            'creator_profile_id'=> $profile->id,
            'content_raw'       => ['roles' => [$role->id]], // sin 'contexts'
        ]);

        // Sin context, debe usar el vector del role sin modificar.
        $qdrant = Mockery::mock(QdrantService::class);
        $qdrant->shouldReceive('searchHub')->with('player_style', [0.6, 0.8], Mockery::any(), Mockery::any(), 10)
            ->once()->andReturn([]);

        $repo = new QdrantHubMatchmakingRepository($qdrant);
        $repo->findProfilesForProjectActivity($activity, 'guild-noctx');
        $this->assertTrue(true);
    }
}
