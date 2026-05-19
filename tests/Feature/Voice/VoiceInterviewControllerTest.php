<?php

namespace Tests\Feature\Voice;

use App\Services\Voice\VoiceInterviewSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class VoiceInterviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $validSecret = 'test-gamma-bot-token-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.discord.bots', [
            'gamma-app-id' => [
                'slug'       => 'gamma',
                'public_key' => 'gamma-public-key',
                'bot_token'  => $this->validSecret,
                'tier'       => 3,
            ],
        ]);
    }

    // ── startSession ─────────────────────────────────────────────────────────

    public function test_start_session_requires_secret_header(): void
    {
        $response = $this->postJson('/api/voice/session/start', [
            'discord_id'       => '123456789',
            'discord_guild_id' => '987654321',
        ]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized']);
    }

    public function test_start_session_with_wrong_secret_returns_401(): void
    {
        $response = $this->postJson('/api/voice/session/start', [
            'discord_id'       => '123456789',
            'discord_guild_id' => '987654321',
        ], ['X-Voice-Bridge-Secret' => 'wrong-secret']);

        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized']);
    }

    public function test_start_session_with_valid_secret_returns_session_data(): void
    {
        $mock = Mockery::mock(VoiceInterviewSessionManager::class);
        $mock->shouldReceive('startSession')
            ->once()
            ->andReturn([
                'session_id'       => 'mock-session-id',
                'opening_question' => '¿Cuáles son tus preferencias de roleplay?',
            ]);
        $this->app->instance(VoiceInterviewSessionManager::class, $mock);

        $response = $this->postJson('/api/voice/session/start', [
            'discord_id'       => '123456789',
            'discord_guild_id' => '987654321',
        ], ['X-Voice-Bridge-Secret' => $this->validSecret]);

        $response->assertStatus(200)
            ->assertJson([
                'session_id'       => 'mock-session-id',
                'opening_question' => '¿Cuáles son tus preferencias de roleplay?',
            ]);
    }

    public function test_start_session_returns_422_when_discord_id_missing(): void
    {
        $response = $this->postJson('/api/voice/session/start', [
            'discord_guild_id' => '987654321',
        ], ['X-Voice-Bridge-Secret' => $this->validSecret]);

        $response->assertStatus(422);
    }

    public function test_start_session_returns_422_when_guild_id_missing(): void
    {
        $response = $this->postJson('/api/voice/session/start', [
            'discord_id' => '123456789',
        ], ['X-Voice-Bridge-Secret' => $this->validSecret]);

        $response->assertStatus(422);
    }

    // ── handleTranscription ──────────────────────────────────────────────────

    public function test_handle_transcription_requires_secret_header(): void
    {
        $response = $this->postJson('/api/voice/transcription', [
            'session_id' => 'test-session',
            'transcript' => 'Quiero ser un mago oscuro',
            'discord_id' => '123456789',
        ]);

        $response->assertStatus(401);
    }

    public function test_handle_transcription_returns_streaming_response(): void
    {
        $response = $this->postJson('/api/voice/transcription', [
            'session_id' => 'test-session',
            'transcript' => 'Quiero ser un mago oscuro',
            'discord_id' => '123456789',
        ], ['X-Voice-Bridge-Secret' => $this->validSecret]);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_handle_transcription_returns_422_when_transcript_empty(): void
    {
        $response = $this->postJson('/api/voice/transcription', [
            'session_id' => 'test-session',
            'transcript' => 'ab',
            'discord_id' => '123456789',
        ], ['X-Voice-Bridge-Secret' => $this->validSecret]);

        $response->assertStatus(422);
    }

    public function test_handle_transcription_returns_422_when_fields_missing(): void
    {
        $response = $this->postJson('/api/voice/transcription', [
            'transcript' => 'Quiero ser un mago oscuro',
        ], ['X-Voice-Bridge-Secret' => $this->validSecret]);

        $response->assertStatus(422);
    }

    // ── pollNextQuestion ─────────────────────────────────────────────────────

    public function test_poll_returns_not_ready_when_redis_empty(): void
    {
        Cache::forget('voice_next_question:test-session-123');

        $response = $this->getJson(
            '/api/voice/next-question/test-session-123',
            ['X-Voice-Bridge-Secret' => $this->validSecret],
        );

        $response->assertStatus(200)
            ->assertJson(['ready' => false]);
    }

    public function test_poll_returns_question_when_redis_has_value(): void
    {
        Cache::put('voice_next_question:test-session-456', '¿Cuál es tu estilo de roleplay?', now()->addMinutes(5));

        $response = $this->getJson(
            '/api/voice/next-question/test-session-456',
            ['X-Voice-Bridge-Secret' => $this->validSecret],
        );

        $response->assertStatus(200)
            ->assertJson([
                'ready'    => true,
                'question' => '¿Cuál es tu estilo de roleplay?',
            ]);
    }

    public function test_poll_clears_redis_key_after_returning_question(): void
    {
        Cache::put('voice_next_question:test-session-789', '¿Cuáles son tus preferencias?', now()->addMinutes(5));

        $this->getJson(
            '/api/voice/next-question/test-session-789',
            ['X-Voice-Bridge-Secret' => $this->validSecret],
        )->assertStatus(200);

        $this->assertNull(Cache::get('voice_next_question:test-session-789'));
    }

    public function test_poll_requires_secret_header(): void
    {
        $response = $this->getJson('/api/voice/next-question/test-session-123');

        $response->assertStatus(401);
    }
}
