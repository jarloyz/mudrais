<?php

namespace Tests\Unit\Matchmaking;

use App\Domains\Matchmaking\Contracts\HubMatchmakingRepositoryInterface;
use App\Domains\Matchmaking\DTOs\HubMatchResultDTO;
use App\Domains\Matchmaking\Services\TeamMatchmakingService;
use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class TeamMatchmakingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_searchForTeam_deduplicates_candidates_across_slots()
    {
        $vault  = Vault::create(['name' => 'Vault Dedup']);
        $parent = Activity::create([
            'vault_id'       => $vault->id,
            'title'          => 'Team Parent',
            'required_slots' => 2,
        ]);

        $slot1 = Activity::create(['vault_id' => $vault->id, 'title' => 'Slot 1', 'parent_activity_id' => $parent->id]);
        $slot2 = Activity::create(['vault_id' => $vault->id, 'title' => 'Slot 2', 'parent_activity_id' => $parent->id]);

        // Candidate "prof-shared" appears in BOTH slots — should only be assigned to the first one.
        $sharedCandidate = new HubMatchResultDTO(
            qdrantId: 'prof-shared',
            entityType: 'player_profile',
            score: 0.95,
            payload: ['profile_id' => 'prof-shared']
        );
        $uniqueCandidate = new HubMatchResultDTO(
            qdrantId: 'prof-unique',
            entityType: 'player_profile',
            score: 0.80,
            payload: ['profile_id' => 'prof-unique']
        );

        $repository = Mockery::mock(HubMatchmakingRepositoryInterface::class);
        $repository->shouldReceive('findProfilesForTeamActivity')
            ->once()
            ->andReturn([
                $slot1->id => ['slot_title' => 'Slot 1', 'candidates' => [$sharedCandidate, $uniqueCandidate]],
                $slot2->id => ['slot_title' => 'Slot 2', 'candidates' => [$sharedCandidate]],
            ]);

        $service = new TeamMatchmakingService($repository);
        $result  = $service->searchForTeam($parent, 'guild-dedup');

        $this->assertCount(2, $result->slots);

        $firstSlot  = $result->slots[0];
        $secondSlot = $result->slots[1];

        // Both candidates appear in slot 1.
        $this->assertCount(2, $firstSlot->candidates);

        // Shared candidate was already seen in slot 1, so slot 2 has 0 candidates.
        $this->assertCount(0, $secondSlot->candidates);

        // TeamMatchResultDTO reports one filled slot.
        $this->assertEquals(1, $result->filledSlots());
    }

    public function test_searchForTeam_returns_correct_slot_titles_and_ids()
    {
        $vault  = Vault::create(['name' => 'Vault Meta']);
        $parent = Activity::create(['vault_id' => $vault->id, 'title' => 'Meta Parent', 'required_slots' => 1]);
        $slot   = Activity::create(['vault_id' => $vault->id, 'title' => 'Dev Slot', 'parent_activity_id' => $parent->id]);

        $candidate = new HubMatchResultDTO('prof-abc', 'player_profile', 0.88, []);

        $repository = Mockery::mock(HubMatchmakingRepositoryInterface::class);
        $repository->shouldReceive('findProfilesForTeamActivity')
            ->once()
            ->andReturn([
                $slot->id => ['slot_title' => 'Dev Slot', 'candidates' => [$candidate]],
            ]);

        $service = new TeamMatchmakingService($repository);
        $result  = $service->searchForTeam($parent, 'guild-meta');

        $this->assertEquals($slot->id, $result->slots[0]->slotActivityId);
        $this->assertEquals('Dev Slot', $result->slots[0]->slotTitle);
        $this->assertTrue($result->isComplete());
    }
}
