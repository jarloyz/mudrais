<?php

namespace Tests\Unit\Application;

use App\Domains\Matchmaking\Models\Archetype;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerArchetypeProfileTest extends TestCase
{
    use RefreshDatabase;

    private Archetype $archetype;
    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archetype = Archetype::create([
            'name'               => 'TTRPG Texto',
            'qdrant_vector_name' => 'ttrpg_text_v1',
        ]);
        $this->player = Player::create(['discord_id' => 'user_001', 'username' => 'Tester']);
    }

    public function test_creates_profile_with_required_fields(): void
    {
        $profile = PlayerArchetypeProfile::create([
            'player_id'      => $this->player->id,
            'discord_user_id' => $this->player->discord_id,
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Cyberpunk', 'Horror Cósmico'],
        ]);

        $this->assertDatabaseHas('player_archetype_profiles', [
            'player_id'    => $this->player->id,
            'archetype_id' => $this->archetype->id,
        ]);

        $this->assertIsArray($profile->positive_prefs);
        $this->assertContains('Cyberpunk', $profile->positive_prefs);
    }

    public function test_unique_constraint_per_player_and_archetype(): void
    {
        PlayerArchetypeProfile::create([
            'player_id'      => $this->player->id,
            'discord_user_id' => $this->player->discord_id,
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Fantasy'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PlayerArchetypeProfile::create([
            'player_id'      => $this->player->id,
            'discord_user_id' => $this->player->discord_id,
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Sci-Fi'],
        ]);
    }

    public function test_scope_for_user(): void
    {
        PlayerArchetypeProfile::create([
            'player_id'      => $this->player->id,
            'discord_user_id' => 'target_user',
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Horror'],
        ]);

        $other   = Player::create(['discord_id' => 'other_id', 'username' => 'Other']);
        $archetype2 = Archetype::create(['name' => 'Gaming', 'qdrant_vector_name' => 'gaming_v1']);

        PlayerArchetypeProfile::create([
            'player_id'      => $other->id,
            'discord_user_id' => 'other_user',
            'archetype_id'   => $archetype2->id,
            'positive_prefs' => ['Action'],
        ]);

        $results = PlayerArchetypeProfile::forUser('target_user')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('target_user', $results->first()->discord_user_id);
    }

    public function test_red_lines_and_metadata_are_nullable(): void
    {
        $profile = PlayerArchetypeProfile::create([
            'player_id'      => $this->player->id,
            'discord_user_id' => $this->player->discord_id,
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Fantasy'],
        ]);

        $this->assertNull($profile->red_lines);
        $this->assertNull($profile->metadata);
        $this->assertNull($profile->qdrant_id);
    }

    public function test_belongs_to_archetype(): void
    {
        $profile = PlayerArchetypeProfile::create([
            'player_id'      => $this->player->id,
            'discord_user_id' => $this->player->discord_id,
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Fantasy'],
        ]);

        $this->assertEquals('ttrpg_text_v1', $profile->archetype->qdrant_vector_name);
    }
}
