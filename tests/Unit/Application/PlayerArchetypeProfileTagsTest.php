<?php

namespace Tests\Unit\Application;

use App\Domains\Matchmaking\Models\Archetype;
use App\Models\CanonicalTag;
use App\Models\Player;
use App\Models\PlayerArchetypeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerArchetypeProfileTagsTest extends TestCase
{
    use RefreshDatabase;

    private Archetype $archetype;
    private PlayerArchetypeProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archetype = Archetype::create([
            'name'               => 'TTRPG Texto',
            'qdrant_vector_name' => 'ttrpg_text_v1',
        ]);

        $player = Player::create(['discord_id' => 'user_tag_test', 'username' => 'TagTester']);

        $this->profile = PlayerArchetypeProfile::create([
            'player_id'      => $player->id,
            'discord_user_id' => $player->discord_id,
            'archetype_id'   => $this->archetype->id,
            'positive_prefs' => ['Fantasy'],
        ]);
    }

    public function test_can_attach_tags_via_morphtomany(): void
    {
        $tag = CanonicalTag::create([
            'slug'      => 'horror',
            'name'      => 'Horror',
            'is_active' => true,
        ]);

        $this->profile->tags()->attach($tag->id, ['tag_context' => 'red_line']);

        $this->assertDatabaseHas('taggables', [
            'canonical_tag_id' => $tag->id,
            'taggable_id'      => $this->profile->id,
            'tag_context'      => 'red_line',
        ]);

        $this->assertCount(1, $this->profile->tags);
    }

    public function test_tags_by_context_filters_correctly(): void
    {
        $redTag  = CanonicalTag::create(['slug' => 'gore', 'name' => 'Gore', 'is_active' => true]);
        $prefTag = CanonicalTag::create(['slug' => 'cyberpunk', 'name' => 'Cyberpunk', 'is_active' => true]);

        $this->profile->tags()->attach($redTag->id,  ['tag_context' => 'red_line']);
        $this->profile->tags()->attach($prefTag->id, ['tag_context' => 'preference']);

        $redLines   = $this->profile->tagsByContext('red_line')->get();
        $prefs      = $this->profile->tagsByContext('preference')->get();
        $yellows    = $this->profile->tagsByContext('yellow_line')->get();

        $this->assertCount(1, $redLines);
        $this->assertEquals('gore', $redLines->first()->slug);

        $this->assertCount(1, $prefs);
        $this->assertEquals('cyberpunk', $prefs->first()->slug);

        $this->assertCount(0, $yellows);
    }

    public function test_sync_tags_replaces_context_only(): void
    {
        $tagA = CanonicalTag::create(['slug' => 'sci-fi', 'name' => 'Sci-Fi', 'is_active' => true]);
        $tagB = CanonicalTag::create(['slug' => 'western', 'name' => 'Western', 'is_active' => true]);
        $tagC = CanonicalTag::create(['slug' => 'nsfw', 'name' => 'NSFW', 'is_active' => true]);

        $this->profile->tags()->attach($tagA->id, ['tag_context' => 'preference']);
        $this->profile->tags()->attach($tagC->id, ['tag_context' => 'red_line']);

        $this->profile->syncTags([$tagB->id], 'preference');

        $prefs    = $this->profile->tagsByContext('preference')->get();
        $redLines = $this->profile->tagsByContext('red_line')->get();

        $this->assertCount(1, $prefs);
        $this->assertEquals('western', $prefs->first()->slug);

        $this->assertCount(1, $redLines);
        $this->assertEquals('nsfw', $redLines->first()->slug);
    }

    public function test_detach_all_clears_profile_tags(): void
    {
        $tag = CanonicalTag::create(['slug' => 'comedy', 'name' => 'Comedy', 'is_active' => true]);
        $this->profile->tags()->attach($tag->id, ['tag_context' => 'preference']);

        $this->profile->tags()->detach();

        $this->assertCount(0, $this->profile->fresh()->tags);
        $this->assertDatabaseMissing('taggables', ['taggable_id' => $this->profile->id]);
    }

    public function test_canonical_tag_profiles_inverse_relation(): void
    {
        $tag = CanonicalTag::create(['slug' => 'drama', 'name' => 'Drama', 'is_active' => true]);
        $this->profile->tags()->attach($tag->id, ['tag_context' => 'preference']);

        $profilesViaTag = $tag->fresh()->profiles;

        $this->assertCount(1, $profilesViaTag);
        $this->assertEquals($this->profile->id, $profilesViaTag->first()->id);
    }
}
