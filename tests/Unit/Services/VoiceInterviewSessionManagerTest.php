<?php

namespace Tests\Unit\Services;

use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Services\Voice\VoiceInterviewSessionManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class VoiceInterviewSessionManagerTest extends TestCase
{
    private InterviewerAgent $interviewer;
    private ArchetypeMutatorService $mutatorService;
    private VoiceInterviewSessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->interviewer    = Mockery::mock(InterviewerAgent::class);
        $this->mutatorService = Mockery::mock(ArchetypeMutatorService::class);

        $this->manager = new VoiceInterviewSessionManager(
            $this->interviewer,
            $this->mutatorService,
        );
    }

    // ── pushNextQuestion / popNextQuestion ───────────────────────────────────

    public function test_push_next_question_stores_in_cache(): void
    {
        Cache::forget('voice_next_question:test-session-push');

        $this->manager->pushNextQuestion('test-session-push', '¿Cuál es tu estilo?');

        $this->assertEquals('¿Cuál es tu estilo?', Cache::get('voice_next_question:test-session-push'));
    }

    public function test_pop_next_question_reads_and_deletes(): void
    {
        Cache::put('voice_next_question:test-session-pop', '¿Cuáles son tus preferencias?', now()->addMinutes(5));

        $question = $this->manager->popNextQuestion('test-session-pop');

        $this->assertEquals('¿Cuáles son tus preferencias?', $question);
        $this->assertNull(Cache::get('voice_next_question:test-session-pop'));
    }

    public function test_pop_next_question_returns_null_when_not_ready(): void
    {
        Cache::forget('voice_next_question:test-session-empty');

        $result = $this->manager->popNextQuestion('test-session-empty');

        $this->assertNull($result);
    }

    // ── getSession / updateSession ────────────────────────────────────────────

    public function test_get_session_returns_null_when_not_found(): void
    {
        Cache::forget('voice_session:nonexistent-session');

        $result = $this->manager->getSession('nonexistent-session');

        $this->assertNull($result);
    }

    public function test_update_session_persists_state(): void
    {
        $state = ['session_id' => 'test-upd', 'turn' => 5, 'status' => 'active'];

        $this->manager->updateSession('test-upd', $state);

        $retrieved = $this->manager->getSession('test-upd');
        $this->assertEquals(5, $retrieved['turn']);
        $this->assertEquals('active', $retrieved['status']);
    }

    // ── advanceToNextArchetype ────────────────────────────────────────────────

    public function test_advance_pops_queue_and_returns_true(): void
    {
        $this->interviewer->shouldReceive('resolveFields')
            ->once()
            ->andReturn([
                ['field_key' => 'preferences', 'is_required' => true, 'field_type' => 'text'],
                ['field_key' => 'style',       'is_required' => true, 'field_type' => 'text'],
            ]);

        $state = [
            'session_id'           => 'test-adv',
            'archetype_queue'      => ['archetype-id-2'],
            'current_archetype_id' => 'archetype-id-1',
            'extracted_fields'     => [],
            'conversation_history' => [],
            'status'               => 'active',
            'turn'                 => 3,
        ];

        $this->manager->updateSession('test-adv', $state);

        $result = $this->manager->advanceToNextArchetype('test-adv');

        $this->assertTrue($result);

        $fresh = $this->manager->getSession('test-adv');
        $this->assertEquals('archetype-id-2', $fresh['current_archetype_id']);
        $this->assertEmpty($fresh['archetype_queue']);
        $this->assertEquals(0, $fresh['turn']);
        $this->assertEmpty($fresh['extracted_fields']);
    }

    public function test_advance_returns_false_when_queue_empty(): void
    {
        $state = [
            'session_id'           => 'test-adv-empty',
            'archetype_queue'      => [],
            'current_archetype_id' => 'archetype-id-1',
            'extracted_fields'     => [],
            'conversation_history' => [],
            'status'               => 'active',
            'turn'                 => 2,
        ];

        $this->manager->updateSession('test-adv-empty', $state);

        $result = $this->manager->advanceToNextArchetype('test-adv-empty');

        $this->assertFalse($result);

        $fresh = $this->manager->getSession('test-adv-empty');
        $this->assertEquals('completed', $fresh['status']);
    }

    public function test_advance_returns_false_when_session_missing(): void
    {
        Cache::forget('voice_session:ghost-session');

        $result = $this->manager->advanceToNextArchetype('ghost-session');

        $this->assertFalse($result);
    }

    // ── acquireProcessingLock ─────────────────────────────────────────────────

    public function test_acquire_processing_lock_returns_lock_object(): void
    {
        $lock = $this->manager->acquireProcessingLock('test-lock-session');

        $this->assertInstanceOf(\Illuminate\Contracts\Cache\Lock::class, $lock);
    }
}
