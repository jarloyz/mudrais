<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\VoiceInterviewerAgent;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VoiceInterviewerAgentTest extends TestCase
{
    use RefreshDatabase;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;
    private VoiceInterviewerAgent $agent;

    private array $allFields = [
        ['field_key' => 'preferences', 'field_label' => 'Preferences', 'is_required' => true,  'hint' => 'Genres', 'field_type' => 'text',   'options' => []],
        ['field_key' => 'experience',  'field_label' => 'Experience',  'is_required' => true,  'hint' => 'Level',  'field_type' => 'select', 'options' => ['beginner', 'intermediate', 'expert']],
        ['field_key' => 'verbosity',   'field_label' => 'Verbosity',   'is_required' => false, 'hint' => '1-10',  'field_type' => 'range',  'options' => ['min' => 1, 'max' => 10]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway  = Mockery::mock(AiChatGateway::class);
        $this->resolver = Mockery::mock(UserAiSettingsResolver::class);

        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('test-model')->byDefault();
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();

        $this->agent = new VoiceInterviewerAgent($this->gateway, $this->resolver);
    }

    public function test_returns_question_from_json_response(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn([
            'text' => json_encode(['next_question' => 'What genres do you enjoy most?']),
        ]);

        $question = $this->agent->formulateQuestion(['preferences'], $this->allFields, []);

        $this->assertEquals('What genres do you enjoy most?', $question);
    }

    public function test_falls_back_to_plain_text_when_no_json(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn([
            'text' => 'Tell me about your favorite genres.',
        ]);

        $question = $this->agent->formulateQuestion(['preferences'], $this->allFields, []);

        $this->assertEquals('Tell me about your favorite genres.', $question);
    }

    public function test_strips_markdown_code_block_from_json_response(): void
    {
        $json = json_encode(['next_question' => 'How experienced are you?']);

        $this->gateway->shouldReceive('chat')->once()->andReturn([
            'text' => "```json\n{$json}\n```",
        ]);

        $question = $this->agent->formulateQuestion(['experience'], $this->allFields, []);

        $this->assertEquals('How experienced are you?', $question);
    }

    public function test_returns_empty_string_when_response_is_empty(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => '']);

        $question = $this->agent->formulateQuestion(['preferences'], $this->allFields, []);

        $this->assertEquals('', $question);
    }

    public function test_sends_only_missing_fields_to_llm(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                // Solo "experience" es missing — "preferences" no debe aparecer en la lista de campos
                return str_contains($system, 'experience')
                    && ! str_contains($system, 'preferences');
            })
            ->andReturn(['text' => json_encode(['next_question' => 'How experienced are you?'])]);

        $this->agent->formulateQuestion(['experience'], $this->allFields, []);
    }

    public function test_includes_select_options_in_prompt(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                return str_contains($system, 'beginner')
                    && str_contains($system, 'intermediate')
                    && str_contains($system, 'expert');
            })
            ->andReturn(['text' => json_encode(['next_question' => 'What is your experience level?'])]);

        $this->agent->formulateQuestion(['experience'], $this->allFields, []);
    }

    public function test_includes_range_scale_in_prompt(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                // El prompt debe mencionar la escala del range
                return str_contains($system, '1') && str_contains($system, '10');
            })
            ->andReturn(['text' => json_encode(['next_question' => 'On a scale from 1 to 10, how verbose are you?'])]);

        $this->agent->formulateQuestion(['verbosity'], $this->allFields, []);
    }

    public function test_required_fields_sorted_before_optional_in_prompt(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                // required aparece antes que optional en el prompt
                $posRequired = strpos($system, '★ required');
                $posOptional = strpos($system, 'optional');
                return $posRequired !== false && $posOptional !== false && $posRequired < $posOptional;
            })
            ->andReturn(['text' => json_encode(['next_question' => 'test'])]);

        // preferences (required) y verbosity (optional) — ambos missing
        $this->agent->formulateQuestion(['verbosity', 'preferences'], $this->allFields, []);
    }

    public function test_uses_db_template_when_available(): void
    {
        AiPromptTemplate::updateOrCreate(
            ['key' => 'voice_interviewer_question'],
            ['body' => 'Custom voice interviewer prompt'],
        );

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                return str_contains($messages[0]['content'] ?? '', 'Custom voice interviewer prompt');
            })
            ->andReturn(['text' => json_encode(['next_question' => 'test question'])]);

        $this->agent->formulateQuestion(['preferences'], $this->allFields, []);
    }

    public function test_passes_conversation_history_to_llm(): void
    {
        $history = [
            ['role' => 'user',      'content' => 'I like fantasy'],
            ['role' => 'assistant', 'content' => 'Great! What is your experience level?'],
        ];

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                return str_contains($system, 'fantasy')
                    && str_contains($system, 'experience level');
            })
            ->andReturn(['text' => json_encode(['next_question' => 'Are you a beginner or more experienced?'])]);

        $question = $this->agent->formulateQuestion(['experience'], $this->allFields, $history);

        $this->assertEquals('Are you a beginner or more experienced?', $question);
    }
}
