<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\VoiceInterviewTurnAgent;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VoiceInterviewTurnAgentTest extends TestCase
{
    use RefreshDatabase;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;
    private VoiceInterviewTurnAgent $agent;

    private array $fields = [
        ['field_key' => 'preferences',  'field_label' => 'Preferences',  'is_required' => true,  'hint' => 'Genres you enjoy',      'field_type' => 'text',        'options' => []],
        ['field_key' => 'experience',   'field_label' => 'Experience',   'is_required' => true,  'hint' => 'Your level',            'field_type' => 'select',      'options' => ['beginner', 'intermediate', 'expert']],
        ['field_key' => 'genres',       'field_label' => 'Genres',       'is_required' => false, 'hint' => 'Tags',                  'field_type' => 'multiselect', 'options' => ['fantasy', 'horror', 'sci-fi']],
        ['field_key' => 'verbosity',    'field_label' => 'Verbosity',    'is_required' => false, 'hint' => '1-10',                  'field_type' => 'range',       'options' => ['min' => 1, 'max' => 10]],
        ['field_key' => 'has_discord',  'field_label' => 'Has Discord',  'is_required' => false, 'hint' => '',                      'field_type' => 'boolean',     'options' => []],
        ['field_key' => 'red_lines',    'field_label' => 'Red Lines',    'is_required' => true,  'hint' => 'Absolute limits',       'field_type' => 'text',        'options' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway  = Mockery::mock(AiChatGateway::class);
        $this->resolver = Mockery::mock(UserAiSettingsResolver::class);

        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('test-model')->byDefault();
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();

        $this->agent = new VoiceInterviewTurnAgent($this->gateway, $this->resolver);
    }

    public function test_returns_extracted_and_next_question_in_single_call(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'dark fantasy'],
            'next_question' => 'What is your experience level? You can say: beginner, intermediate, or expert.',
        ])]);

        $result = $this->agent->processTurn('I love dark fantasy', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
        $this->assertEquals('dark fantasy', $result['extracted']['preferences']);
        $this->assertNotNull($result['next_question']);
        $this->assertStringContainsString('experience', $result['next_question']);
    }

    public function test_extracts_select_field_normalized(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['experience' => 'beginner'],
            'next_question' => 'What genres do you enjoy?',
        ])]);

        $result = $this->agent->processTurn("kind of new to this", $this->fields, []);

        $this->assertEquals('beginner', $result['extracted']['experience']);
    }

    public function test_converts_array_multiselect_to_comma_separated(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['genres' => ['fantasy', 'horror']],
            'next_question' => null,
        ])]);

        $result = $this->agent->processTurn('I play fantasy and horror', $this->fields, []);

        $this->assertEquals('fantasy, horror', $result['extracted']['genres']);
    }

    public function test_off_topic_returns_empty_extracted_and_null_question(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'off_topic',
            'extracted'     => ['preferences' => 'should be ignored'],
            'next_question' => 'ignored question',
        ])]);

        $result = $this->agent->processTurn('What is the weather?', $this->fields, []);

        $this->assertEquals('off_topic', $result['response_type']);
        $this->assertEmpty($result['extracted']);
        $this->assertNull($result['next_question']);
    }

    public function test_invalid_json_falls_back_to_empty_answer(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => 'not json at all']);

        $result = $this->agent->processTurn('something', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
        $this->assertEmpty($result['extracted']);
        $this->assertNull($result['next_question']);
    }

    public function test_strips_markdown_code_fence_from_response(): void
    {
        $json = json_encode([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'sci-fi'],
            'next_question' => 'Next question.',
        ]);

        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => "```json\n{$json}\n```"]);

        $result = $this->agent->processTurn('I love sci-fi', $this->fields, []);

        $this->assertEquals('sci-fi', $result['extracted']['preferences']);
        $this->assertEquals('Next question.', $result['next_question']);
    }

    public function test_filters_empty_extracted_values(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => '', 'experience' => 'beginner'],
            'next_question' => null,
        ])]);

        $result = $this->agent->processTurn('transcript', $this->fields, []);

        $this->assertArrayNotHasKey('preferences', $result['extracted']);
        $this->assertArrayHasKey('experience', $result['extracted']);
    }

    public function test_unknown_response_type_defaults_to_answer(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'spam',
            'extracted'     => ['preferences' => 'fantasy'],
            'next_question' => 'A question.',
        ])]);

        $result = $this->agent->processTurn('transcript', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
    }

    public function test_null_next_question_is_preserved(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'fantasy', 'experience' => 'beginner', 'red_lines' => 'none'],
            'next_question' => null,
        ])]);

        $result = $this->agent->processTurn('all done', $this->fields, []);

        $this->assertNull($result['next_question']);
    }

    public function test_injects_last_question_from_conversation_history(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => 'What are your absolute limits?'],
            ['role' => 'user',      'content' => 'I hate romantic roleplay'],
            ['role' => 'assistant', 'content' => 'Could you tell me about topics you never want to see?'],
        ];

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                return str_contains($system, 'Could you tell me about topics you never want to see?');
            })
            ->andReturn(['text' => json_encode([
                'response_type' => 'answer',
                'extracted'     => ['red_lines' => 'romantic roleplay'],
                'next_question' => 'What is your experience level?',
            ])]);

        $result = $this->agent->processTurn('romantic roleplay', $this->fields, [], $history);

        $this->assertEquals('red_lines', array_key_first($result['extracted']));
    }

    public function test_uses_most_recent_assistant_message_as_last_question(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => 'First question'],
            ['role' => 'user',      'content' => 'I love fantasy'],
            ['role' => 'assistant', 'content' => 'Now tell me about your play style'],
        ];

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                // "Now tell me about your play style" must appear in the "Last Question Asked" block.
                // "First question" may appear in the conversation history block — that's expected.
                // We verify the most recent assistant message is the one surfaced as the primary context.
                $lastQuestionBlock = str_contains($system, "## Last Question Asked");
                $hasRecentQuestion = str_contains($system, 'Now tell me about your play style');
                return $lastQuestionBlock && $hasRecentQuestion;
            })
            ->andReturn(['text' => json_encode([
                'response_type' => 'answer',
                'extracted'     => [],
                'next_question' => 'A question.',
            ])]);

        $this->agent->processTurn('I like long stories', $this->fields, [], $history);
    }

    public function test_works_without_conversation_history(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => json_encode([
                'response_type' => 'answer',
                'extracted'     => ['preferences' => 'fantasy'],
                'next_question' => 'What is your experience level?',
            ])]);

        $result = $this->agent->processTurn('I love fantasy', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
    }

    public function test_uses_db_template_when_available(): void
    {
        AiPromptTemplate::updateOrCreate(
            ['key' => 'voice_interview_turn'],
            ['body' => 'Custom turn prompt for {fields_json} and {extracted_json} and {last_question}'],
        );

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                return ! str_contains($system, '{fields_json}')
                    && ! str_contains($system, '{extracted_json}')
                    && ! str_contains($system, '{last_question}')
                    && str_contains($system, 'Custom turn prompt');
            })
            ->andReturn(['text' => json_encode([
                'response_type' => 'answer',
                'extracted'     => [],
                'next_question' => null,
            ])]);

        $this->agent->processTurn('transcript', $this->fields, []);
    }

    public function test_extracts_boolean_and_range_fields(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['has_discord' => 'yes', 'verbosity' => '7'],
            'next_question' => null,
        ])]);

        $result = $this->agent->processTurn('Yes I have Discord and verbosity seven', $this->fields, []);

        $this->assertEquals('yes', $result['extracted']['has_discord']);
        $this->assertEquals('7', $result['extracted']['verbosity']);
    }
}
