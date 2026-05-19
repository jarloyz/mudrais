<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypePrompt;
use App\Domains\Matchmaking\Services\ArchetypeMutatorService;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class InterviewerAgentTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;
    private ArchetypeMutatorService $mutatorService;

    private array $fields = [
        ['field_key' => 'preferences', 'field_label' => 'Preferences', 'is_required' => true,  'hint' => 'Genres', 'field_type' => 'text', 'options' => []],
        ['field_key' => 'style',       'field_label' => 'Play Style',  'is_required' => true,  'hint' => 'Tone',   'field_type' => 'text', 'options' => []],
        ['field_key' => 'red_lines',   'field_label' => 'Red Lines',   'is_required' => false, 'hint' => 'Limits', 'field_type' => 'text', 'options' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();

        $this->gateway        = Mockery::mock(AiChatGateway::class);
        $this->resolver       = Mockery::mock(UserAiSettingsResolver::class);
        $this->mutatorService = Mockery::mock(ArchetypeMutatorService::class);

        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('test-model')->byDefault();
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();
    }

    public function test_formulate_question_returns_string_from_json_response(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => json_encode(['next_question' => 'What genres do you enjoy?'])]);

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $question = $agent->formulateQuestion(['preferences'], $this->fields, []);

        $this->assertEquals('What genres do you enjoy?', $question);
    }

    public function test_formulate_question_falls_back_to_plain_text_when_no_json(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => 'Tell me about your favorite genres.']);

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $question = $agent->formulateQuestion(['preferences'], $this->fields, []);

        $this->assertEquals('Tell me about your favorite genres.', $question);
    }

    public function test_formulate_question_strips_markdown_code_blocks(): void
    {
        $jsonPayload = json_encode(['next_question' => 'What is your play style?']);

        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => "```json\n{$jsonPayload}\n```"]);

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $question = $agent->formulateQuestion(['style'], $this->fields, []);

        $this->assertEquals('What is your play style?', $question);
    }

    public function test_formulate_question_returns_empty_string_on_empty_response(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => '']);

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $question = $agent->formulateQuestion(['preferences'], $this->fields, []);

        $this->assertEquals('', $question);
    }

    public function test_formulate_question_uses_archetype_interviewer_prompt(): void
    {
        $archetype = Archetype::create([
            'name'               => 'Cyberpunk',
            'qdrant_vector_name' => 'cyberpunk_v1',
        ]);

        ArchetypePrompt::create([
            'archetype_id'  => $archetype->id,
            'agent_type'    => 'interviewer',
            'system_prompt' => 'You are a gritty street interviewer in the neon city.',
        ]);

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $systemPrompt = $messages[0]['content'] ?? '';
                return str_contains($systemPrompt, 'gritty street interviewer');
            })
            ->andReturn(['text' => json_encode(['next_question' => 'What neon street do you haunt?'])]);

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $question = $agent->formulateQuestion(['preferences'], $this->fields, [], $archetype->id);

        $this->assertNotEmpty($question);
    }

    public function test_resolve_fields_returns_defaults_when_no_archetype(): void
    {
        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $fields = $agent->resolveFields(null);

        $this->assertNotEmpty($fields);
        $fieldKeys = array_column($fields, 'field_key');
        $this->assertContains('preferences', $fieldKeys);
        $this->assertContains('style', $fieldKeys);
    }

    public function test_resolve_fields_returns_defaults_when_mutator_empty(): void
    {
        $this->mutatorService->shouldReceive('getFieldsForContext')
            ->once()
            ->andReturn(collect([]));

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $fields = $agent->resolveFields('some-archetype-id');

        $this->assertNotEmpty($fields);
        $this->assertContains('preferences', array_column($fields, 'field_key'));
    }

    public function test_build_embed_fields_skips_empty_values(): void
    {
        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $embeds = $agent->buildEmbedFields(
            ['preferences' => 'sci-fi', 'style' => ''],
            $this->fields,
        );

        $this->assertCount(1, $embeds);
        $this->assertEquals('Preferences', $embeds[0]['name']);
    }

    public function test_build_embed_fields_truncates_long_values(): void
    {
        $longValue = str_repeat('a', 2000);

        $agent = new InterviewerAgent($this->gateway, $this->resolver, $this->mutatorService);

        $embeds = $agent->buildEmbedFields(
            ['preferences' => $longValue],
            $this->fields,
        );

        $this->assertLessThanOrEqual(1024, mb_strlen($embeds[0]['value']));
    }

    public function test_ai_field_types_constant_contains_text_types(): void
    {
        $this->assertContains('text',       InterviewerAgent::AI_FIELD_TYPES);
        $this->assertContains('text_short', InterviewerAgent::AI_FIELD_TYPES);
        $this->assertContains('text_long',  InterviewerAgent::AI_FIELD_TYPES);
        $this->assertNotContains('select',  InterviewerAgent::AI_FIELD_TYPES);
        $this->assertNotContains('boolean', InterviewerAgent::AI_FIELD_TYPES);
        $this->assertNotContains('range',   InterviewerAgent::AI_FIELD_TYPES);
    }
}
