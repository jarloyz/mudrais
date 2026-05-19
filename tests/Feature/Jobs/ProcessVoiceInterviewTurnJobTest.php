<?php

namespace Tests\Feature\Jobs;

use App\Domains\Matchmaking\Models\Archetype;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Infrastructure\Ai\Agents\VoiceAnalystAgent;
use App\Infrastructure\Ai\Agents\VoiceInterviewTurnAgent;
use App\Jobs\Voice\ProcessVoiceInterviewTurnJob;
use App\Services\Voice\VoiceInterviewSessionManager;
use App\Services\Voice\VoiceTextTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ProcessVoiceInterviewTurnJobTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId = 'test-voice-session-job';
    private array $baseState;

    protected function setUp(): void
    {
        parent::setUp();

        $archetype = Archetype::create([
            'name'               => 'Test Archetype',
            'slug'               => 'test-archetype',
            'qdrant_vector_name' => 'test_vector',
        ]);

        $this->baseState = [
            'session_id'            => $this->sessionId,
            'discord_id'            => '123456789',
            'discord_guild_id'      => '987654321',
            'player_id'             => null,
            'username'              => 'testuser',
            'locale'                => 'es',
            'archetype_queue'       => [],
            'current_archetype_id'  => (string) $archetype->id,
            'turn'                  => 0,
            'extracted_fields'      => [],
            'conversation_history'  => [],
            'missing_required_keys' => ['preferences', 'style'],
            'missing_optional_keys' => [],
            'status'                => 'active',
            'started_at'            => time(),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeJob(string $transcript = 'test'): ProcessVoiceInterviewTurnJob
    {
        return new ProcessVoiceInterviewTurnJob($this->sessionId, $transcript, '123456789');
    }

    private function runJob(
        ProcessVoiceInterviewTurnJob $job,
        ?VoiceInterviewTurnAgent $turnAgent = null,
        ?VoiceAnalystAgent $analyst = null,
        ?InterviewerAgent $interviewer = null,
        ?VoiceInterviewSessionManager $sessionManager = null,
        ?VoiceTextTranslator $translator = null,
    ): void {
        $job->handle(
            $turnAgent      ?? Mockery::mock(VoiceInterviewTurnAgent::class),
            $analyst        ?? Mockery::mock(VoiceAnalystAgent::class),
            $interviewer    ?? Mockery::mock(InterviewerAgent::class),
            $sessionManager ?? app(VoiceInterviewSessionManager::class),
            $translator     ?? Mockery::mock(VoiceTextTranslator::class),
        );
    }

    private function defaultFields(): array
    {
        return [
            ['field_key' => 'preferences', 'field_label' => 'Preferences', 'is_required' => true,  'field_type' => 'text', 'hint' => '', 'options' => []],
            ['field_key' => 'style',       'field_label' => 'Style',       'is_required' => true,  'field_type' => 'text', 'hint' => '', 'options' => []],
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_job_aborts_when_session_not_found(): void
    {
        Cache::forget("voice_session:{$this->sessionId}");

        $sessionManager = Mockery::mock(VoiceInterviewSessionManager::class);
        $sessionManager->shouldReceive('getSession')->once()->andReturn(null);
        $sessionManager->shouldNotReceive('acquireProcessingLock');

        $this->runJob(
            $this->makeJob(),
            sessionManager: $sessionManager,
        );
    }

    public function test_job_does_not_acquire_lock_when_session_missing(): void
    {
        Cache::forget("voice_session:{$this->sessionId}");

        $sessionManager = Mockery::mock(VoiceInterviewSessionManager::class);
        $sessionManager->shouldReceive('getSession')->once()->andReturn(null);
        $sessionManager->shouldNotReceive('acquireProcessingLock');

        $turnAgent   = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst     = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer = Mockery::mock(InterviewerAgent::class);
        $translator  = Mockery::mock(VoiceTextTranslator::class);

        $this->makeJob()->handle($turnAgent, $analyst, $interviewer, $sessionManager, $translator);
    }

    public function test_job_pushes_next_question_when_fields_incomplete(): void
    {
        Cache::put("voice_session:{$this->sessionId}", $this->baseState, now()->addMinutes(60));

        $turnAgent      = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst        = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer    = Mockery::mock(InterviewerAgent::class);
        $sessionManager = app(VoiceInterviewSessionManager::class);

        $interviewer->shouldReceive('resolveFields')->once()->andReturn($this->defaultFields());

        $turnAgent->shouldReceive('processTurn')->once()->andReturn([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'Fantasy and sci-fi'],
            'next_question' => 'How would you describe your play style?',
        ]);

        $analyst->shouldReceive('analyze')->once()->andReturn([
            'is_complete'      => false,
            'missing_required' => ['style'],
            'missing_optional' => [],
            'complete_fields'  => ['preferences' => 'Fantasy and sci-fi'],
        ]);

        $this->runJob($this->makeJob('I love fantasy'), $turnAgent, $analyst, $interviewer, $sessionManager);

        $question = $sessionManager->popNextQuestion($this->sessionId);
        $this->assertEquals('How would you describe your play style?', $question);
    }

    public function test_job_handles_off_topic_without_advancing_turn(): void
    {
        Cache::put("voice_session:{$this->sessionId}", $this->baseState, now()->addMinutes(60));

        $turnAgent      = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst        = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer    = Mockery::mock(InterviewerAgent::class);
        $sessionManager = app(VoiceInterviewSessionManager::class);

        $interviewer->shouldReceive('resolveFields')->once()->andReturn($this->defaultFields());

        $turnAgent->shouldReceive('processTurn')->once()->andReturn([
            'response_type' => 'off_topic',
            'extracted'     => [],
            'next_question' => null,
        ]);

        $analyst->shouldNotReceive('analyze');

        $this->runJob($this->makeJob('¿Cuál es el tiempo hoy?'), $turnAgent, $analyst, $interviewer, $sessionManager);

        // Turn must NOT advance for off-topic
        $freshState = $sessionManager->getSession($this->sessionId);
        $this->assertEquals(0, $freshState['turn']);

        // But a redirect message must be pushed
        $redirect = $sessionManager->popNextQuestion($this->sessionId);
        $this->assertNotNull($redirect);
    }

    public function test_job_does_not_call_translator_for_voice_questions(): void
    {
        Cache::put("voice_session:{$this->sessionId}", $this->baseState, now()->addMinutes(60));

        $turnAgent      = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst        = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer    = Mockery::mock(InterviewerAgent::class);
        $translator     = Mockery::mock(VoiceTextTranslator::class);
        $sessionManager = app(VoiceInterviewSessionManager::class);

        $interviewer->shouldReceive('resolveFields')->once()->andReturn($this->defaultFields());
        $turnAgent->shouldReceive('processTurn')->once()->andReturn([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'fantasy'],
            'next_question' => 'And your play style?',
        ]);
        $analyst->shouldReceive('analyze')->once()->andReturn([
            'is_complete'      => false,
            'missing_required' => ['style'],
            'missing_optional' => [],
            'complete_fields'  => ['preferences' => 'fantasy'],
        ]);

        // VoiceInterviewTurnAgent already returns English — translator must never be called
        $translator->shouldNotReceive('toEnglish');

        $this->runJob($this->makeJob('fantasy'), $turnAgent, $analyst, $interviewer, $sessionManager, $translator);
    }

    public function test_job_pushes_error_message_and_rethrows_on_exception(): void
    {
        Cache::put("voice_session:{$this->sessionId}", $this->baseState, now()->addMinutes(60));

        $turnAgent      = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst        = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer    = Mockery::mock(InterviewerAgent::class);
        $sessionManager = app(VoiceInterviewSessionManager::class);

        $interviewer->shouldReceive('resolveFields')->once()->andReturn($this->defaultFields());
        $turnAgent->shouldReceive('processTurn')->once()->andThrow(new \RuntimeException('AI service unavailable'));

        $this->expectException(\RuntimeException::class);

        $this->runJob($this->makeJob('test'), $turnAgent, $analyst, $interviewer, $sessionManager);

        $errMsg = $sessionManager->popNextQuestion($this->sessionId);
        $this->assertNotNull($errMsg);
    }

    public function test_turn_increments_after_valid_answer(): void
    {
        Cache::put("voice_session:{$this->sessionId}", $this->baseState, now()->addMinutes(60));

        $turnAgent      = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst        = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer    = Mockery::mock(InterviewerAgent::class);
        $sessionManager = app(VoiceInterviewSessionManager::class);

        $interviewer->shouldReceive('resolveFields')->once()->andReturn($this->defaultFields());
        $turnAgent->shouldReceive('processTurn')->once()->andReturn([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'dark fantasy'],
            'next_question' => 'How about your style?',
        ]);
        $analyst->shouldReceive('analyze')->once()->andReturn([
            'is_complete'      => false,
            'missing_required' => ['style'],
            'missing_optional' => [],
            'complete_fields'  => ['preferences' => 'dark fantasy'],
        ]);

        $this->runJob($this->makeJob('dark fantasy'), $turnAgent, $analyst, $interviewer, $sessionManager);

        $freshState = $sessionManager->getSession($this->sessionId);
        $this->assertEquals(1, $freshState['turn']);
        $this->assertEquals(['preferences' => 'dark fantasy'], $freshState['extracted_fields']);
    }

    public function test_uses_llm_next_question_directly_without_extra_agent_call(): void
    {
        Cache::put("voice_session:{$this->sessionId}", $this->baseState, now()->addMinutes(60));

        $turnAgent      = Mockery::mock(VoiceInterviewTurnAgent::class);
        $analyst        = Mockery::mock(VoiceAnalystAgent::class);
        $interviewer    = Mockery::mock(InterviewerAgent::class);
        $sessionManager = app(VoiceInterviewSessionManager::class);

        $interviewer->shouldReceive('resolveFields')->once()->andReturn($this->defaultFields());

        // Single call — returns both extraction AND next question
        $turnAgent->shouldReceive('processTurn')
            ->once()
            ->andReturn([
                'response_type' => 'answer',
                'extracted'     => ['preferences' => 'fantasy'],
                'next_question' => 'What is your experience level? You can say: beginner, intermediate, or expert.',
            ]);

        $analyst->shouldReceive('analyze')->once()->andReturn([
            'is_complete'      => false,
            'missing_required' => ['style'],
            'missing_optional' => [],
            'complete_fields'  => ['preferences' => 'fantasy'],
        ]);

        $this->runJob($this->makeJob('fantasy'), $turnAgent, $analyst, $interviewer, $sessionManager);

        $question = $sessionManager->popNextQuestion($this->sessionId);
        $this->assertStringContainsString('experience level', $question);
    }
}
