<?php

namespace Tests\Unit\Ai;

use App\Infrastructure\Ai\Agents\VoiceAnalystAgent;
use Tests\TestCase;

class VoiceAnalystAgentTest extends TestCase
{
    private VoiceAnalystAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new VoiceAnalystAgent();
    }

    // ── Diferencias clave respecto a RegistrationAnalystAgent ─────────────────

    public function test_single_char_value_counts_as_complete(): void
    {
        // "no" tiene 2 chars — inválido en RegistrationAnalystAgent (min=3), válido aquí (min=1)
        $result = $this->agent->analyze(
            ['has_experience' => 'no'],
            ['has_experience'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_required']);
    }

    public function test_single_digit_range_value_counts_as_complete(): void
    {
        // "5" tiene 1 char — representa un campo range numérico
        $result = $this->agent->analyze(
            ['verbosity' => '5'],
            ['verbosity'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_required']);
    }

    public function test_single_word_select_counts_as_complete(): void
    {
        $result = $this->agent->analyze(
            ['experience' => 'beginner'],
            ['experience'],
        );

        $this->assertTrue($result['is_complete']);
    }

    // ── Comportamiento compartido con RegistrationAnalystAgent ────────────────

    public function test_empty_value_is_not_complete(): void
    {
        $result = $this->agent->analyze(
            ['experience' => ''],
            ['experience'],
        );

        $this->assertFalse($result['is_complete']);
        $this->assertContains('experience', $result['missing_required']);
    }

    public function test_whitespace_only_is_not_complete(): void
    {
        $result = $this->agent->analyze(
            ['experience' => '   '],
            ['experience'],
        );

        $this->assertFalse($result['is_complete']);
    }

    public function test_all_required_present_returns_complete(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy', 'style' => 'action'],
            ['preferences', 'style'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_required']);
    }

    public function test_missing_one_required_returns_incomplete(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy'],
            ['preferences', 'style'],
        );

        $this->assertFalse($result['is_complete']);
        $this->assertContains('style', $result['missing_required']);
        $this->assertNotContains('preferences', $result['missing_required']);
    }

    public function test_optional_fields_reported_separately(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy'],
            ['preferences'],
            ['red_lines'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertContains('red_lines', $result['missing_optional']);
    }

    public function test_complete_optional_not_in_missing_optional(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy', 'red_lines' => 'no gore'],
            ['preferences'],
            ['red_lines'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_optional']);
    }

    public function test_no_required_fields_always_complete(): void
    {
        $result = $this->agent->analyze([], []);

        $this->assertTrue($result['is_complete']);
    }

    public function test_complete_fields_returned_in_output(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy', 'verbosity' => '3'],
            ['preferences', 'verbosity'],
        );

        $this->assertArrayHasKey('complete_fields', $result);
        $this->assertArrayHasKey('preferences', $result['complete_fields']);
        $this->assertArrayHasKey('verbosity', $result['complete_fields']);
    }

    public function test_multiselect_comma_separated_counts_as_complete(): void
    {
        $result = $this->agent->analyze(
            ['genres' => 'fantasy, horror'],
            ['genres'],
        );

        $this->assertTrue($result['is_complete']);
    }

    public function test_missing_keys_return_values_are_lists(): void
    {
        $result = $this->agent->analyze(
            [],
            ['preferences', 'style'],
            ['red_lines'],
        );

        $this->assertIsArray($result['missing_required']);
        $this->assertIsArray($result['missing_optional']);
        $this->assertContains('preferences', $result['missing_required']);
        $this->assertContains('style', $result['missing_required']);
        $this->assertContains('red_lines', $result['missing_optional']);
    }
}
