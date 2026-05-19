<?php

namespace Tests\Unit\Ai;

use App\Application\Contracts\AiChatGateway;
use App\Infrastructure\Ai\Agents\VoiceGatekeeperAgent;
use App\Models\AiPromptTemplate;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VoiceGatekeeperAgentTest extends TestCase
{
    use RefreshDatabase;

    private AiChatGateway $gateway;
    private UserAiSettingsResolver $resolver;
    private VoiceGatekeeperAgent $agent;

    private array $fields = [
        ['field_key' => 'preferences',  'field_label' => 'Preferences',  'is_required' => true,  'hint' => 'Genres', 'field_type' => 'text',        'options' => []],
        ['field_key' => 'experience',   'field_label' => 'Experience',   'is_required' => true,  'hint' => 'Level',  'field_type' => 'select',      'options' => ['beginner', 'intermediate', 'expert']],
        ['field_key' => 'genres',       'field_label' => 'Genres',       'is_required' => false, 'hint' => 'Tags',   'field_type' => 'multiselect', 'options' => ['fantasy', 'horror', 'sci-fi']],
        ['field_key' => 'verbosity',    'field_label' => 'Verbosity',    'is_required' => false, 'hint' => '1-10',   'field_type' => 'range',       'options' => ['min' => 1, 'max' => 10]],
        ['field_key' => 'has_discord',  'field_label' => 'Has Discord',  'is_required' => false, 'hint' => '',       'field_type' => 'boolean',     'options' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway  = Mockery::mock(AiChatGateway::class);
        $this->resolver = Mockery::mock(UserAiSettingsResolver::class);

        $this->resolver->shouldReceive('resolveAgentModel')->andReturn('test-model')->byDefault();
        $this->resolver->shouldReceive('resolveAgentProvider')->andReturn(null)->byDefault();

        $this->agent = new VoiceGatekeeperAgent($this->gateway, $this->resolver);
    }

    public function test_extracts_text_field_from_transcript(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => 'I love dark fantasy and gothic horror'],
        ])]);

        $result = $this->agent->process('I love dark fantasy and gothic horror', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
        $this->assertEquals('I love dark fantasy and gothic horror', $result['extracted']['preferences']);
    }

    public function test_extracts_select_field_normalized_to_exact_option(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['experience' => 'beginner'],
        ])]);

        $result = $this->agent->process("I'm pretty new to this", $this->fields, []);

        $this->assertEquals('beginner', $result['extracted']['experience']);
    }

    public function test_converts_array_multiselect_to_comma_separated_string(): void
    {
        // LLM puede devolver un array para multiselect — debe convertirse a string csv
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['genres' => ['fantasy', 'horror']],
        ])]);

        $result = $this->agent->process('I play fantasy and horror', $this->fields, []);

        $this->assertEquals('fantasy, horror', $result['extracted']['genres']);
    }

    public function test_off_topic_response_returns_empty_extracted(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'off_topic',
            'extracted'     => ['preferences' => 'should be ignored'],
        ])]);

        $result = $this->agent->process('What is the weather today?', $this->fields, []);

        $this->assertEquals('off_topic', $result['response_type']);
        $this->assertEmpty($result['extracted']);
    }

    public function test_invalid_json_falls_back_to_answer_with_empty_extracted(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => 'not json at all']);

        $result = $this->agent->process('something', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
        $this->assertEmpty($result['extracted']);
    }

    public function test_strips_markdown_code_block_from_response(): void
    {
        $json = json_encode(['response_type' => 'answer', 'extracted' => ['preferences' => 'sci-fi']]);

        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => "```json\n{$json}\n```"]);

        $result = $this->agent->process('I love sci-fi', $this->fields, []);

        $this->assertEquals('sci-fi', $result['extracted']['preferences']);
    }

    public function test_filters_empty_extracted_values(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['preferences' => '', 'experience' => 'beginner'],
        ])]);

        $result = $this->agent->process('transcript', $this->fields, []);

        $this->assertArrayNotHasKey('preferences', $result['extracted']);
        $this->assertArrayHasKey('experience', $result['extracted']);
    }

    public function test_unknown_response_type_defaults_to_answer(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'spam',
            'extracted'     => ['preferences' => 'fantasy'],
        ])]);

        $result = $this->agent->process('transcript', $this->fields, []);

        // "spam" no es válido — debe tratar como "answer" para no silenciar respuestas reales
        $this->assertEquals('answer', $result['response_type']);
    }

    public function test_uses_db_template_when_available(): void
    {
        AiPromptTemplate::updateOrCreate(
            ['key' => 'voice_gatekeeper'],
            ['body' => 'Custom gatekeeper prompt for {fields_json} and {extracted_json} and {last_question}'],
        );

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                return ! str_contains($system, '{fields_json}')
                    && ! str_contains($system, '{extracted_json}')
                    && ! str_contains($system, '{last_question}')
                    && str_contains($system, 'Custom gatekeeper prompt');
            })
            ->andReturn(['text' => json_encode(['response_type' => 'answer', 'extracted' => []])]);

        $this->agent->process('transcript', $this->fields, []);
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
                // La última pregunta del entrevistador debe aparecer en el prompt
                return str_contains($system, 'Could you tell me about topics you never want to see?');
            })
            ->andReturn(['text' => json_encode(['response_type' => 'answer', 'extracted' => ['red_lines' => 'romantic roleplay']])]);

        $result = $this->agent->process('romantic roleplay', $this->fields, [], null, $history);

        $this->assertEquals('red_lines', array_key_first($result['extracted']));
    }

    public function test_uses_most_recent_assistant_message_as_last_question(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => 'First question about preferences'],
            ['role' => 'user',      'content' => 'I love fantasy'],
            ['role' => 'assistant', 'content' => 'Now tell me about your play style'],
        ];

        $this->gateway->shouldReceive('chat')
            ->once()
            ->withArgs(function ($model, $messages) {
                $system = $messages[0]['content'] ?? '';
                return str_contains($system, 'Now tell me about your play style')
                    && ! str_contains($system, 'First question about preferences');
            })
            ->andReturn(['text' => json_encode(['response_type' => 'answer', 'extracted' => []])]);

        $this->agent->process('I like long stories', $this->fields, [], null, $history);
    }

    public function test_works_without_conversation_history(): void
    {
        $this->gateway->shouldReceive('chat')
            ->once()
            ->andReturn(['text' => json_encode(['response_type' => 'answer', 'extracted' => ['preferences' => 'fantasy']])]);

        // Sin historial no debe lanzar error
        $result = $this->agent->process('I love fantasy', $this->fields, []);

        $this->assertEquals('answer', $result['response_type']);
    }

    public function test_extracts_boolean_field(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['has_discord' => 'yes'],
        ])]);

        $result = $this->agent->process('Yes I have Discord', $this->fields, []);

        $this->assertEquals('yes', $result['extracted']['has_discord']);
    }

    public function test_extracts_range_field_as_string(): void
    {
        $this->gateway->shouldReceive('chat')->once()->andReturn(['text' => json_encode([
            'response_type' => 'answer',
            'extracted'     => ['verbosity' => '7'],
        ])]);

        $result = $this->agent->process('I would say about a 7', $this->fields, []);

        $this->assertEquals('7', $result['extracted']['verbosity']);
    }
}
