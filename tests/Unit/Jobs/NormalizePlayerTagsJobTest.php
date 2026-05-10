<?php

namespace Tests\Unit\Jobs;

use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Jobs\IndexPlayerStyleJob;
use App\Jobs\NormalizePlayerTagsJob;
use App\Jobs\NormalizeSingleTagJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NormalizePlayerTagsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_one_job_per_term(): void
    {
        Bus::fake();

        $profile = PlayerArchetypeProfile::factory()->create([
            'discord_user_id' => 'discord123',
            'player_id'       => Player::factory()->create()->id,
            'archetype_id'    => Archetype::factory()->create()->id,
        ]);

        $job = new NormalizePlayerTagsJob(
            profile:          $profile,
            redLines:         ['gore'],
            yellowLines:      ['explicit romance'],
            affinities:       ['epic fantasy'],
            semanticTagQuery: 'grimdark, investigative',
        );

        $job->handle();

        // 1 red + 1 yellow + 1 preference + 2 semantic = 5 tag jobs + IndexPlayerStyleJob al final
        // Bus::chain despacha el primero; el resto va en su propiedad chained
        Bus::assertChained([
            NormalizeSingleTagJob::class,
            NormalizeSingleTagJob::class,
            NormalizeSingleTagJob::class,
            NormalizeSingleTagJob::class,
            NormalizeSingleTagJob::class,
            IndexPlayerStyleJob::class,
        ]);
    }

    public function test_it_dispatches_correct_contexts(): void
    {
        Bus::fake();

        $profile = PlayerArchetypeProfile::factory()->create([
            'discord_user_id' => 'discord456',
            'player_id'       => Player::factory()->create()->id,
            'archetype_id'    => Archetype::factory()->create()->id,
        ]);

        $job = new NormalizePlayerTagsJob(
            profile:     $profile,
            redLines:    ['violence'],
            yellowLines: [],
            affinities:  ['mystery'],
        );

        $job->handle();

        // El primer job (red_line) es despachado directamente
        Bus::assertDispatched(NormalizeSingleTagJob::class, fn($j) =>
            $j->profileId === $profile->id
            && $j->term === 'violence'
            && $j->tagContext === 'red_line'
        );

        // Chain: [red_line, preference, IndexPlayerStyleJob]
        Bus::assertChained([
            NormalizeSingleTagJob::class,
            NormalizeSingleTagJob::class,
            IndexPlayerStyleJob::class,
        ]);
    }

    public function test_index_player_style_is_last_in_chain(): void
    {
        Bus::fake();

        $profile = PlayerArchetypeProfile::factory()->create([
            'discord_user_id' => 'discord789',
            'player_id'       => Player::factory()->create()->id,
            'archetype_id'    => Archetype::factory()->create()->id,
        ]);

        $job = new NormalizePlayerTagsJob(
            profile:    $profile,
            redLines:   ['violence'],
            yellowLines: [],
            affinities:  [],
        );

        $job->handle();

        Bus::assertChained([
            NormalizeSingleTagJob::class,
            IndexPlayerStyleJob::class,
        ]);
    }

    public function test_normalize_single_tag_job_uses_tags_queue(): void
    {
        $job = new NormalizeSingleTagJob(
            avatarId:   null,
            profileId:  'some-profile-id',
            term:       'epic fantasy',
            tagContext: 'preference',
        );
        $this->assertSame('tags', $job->queue);
    }
}
