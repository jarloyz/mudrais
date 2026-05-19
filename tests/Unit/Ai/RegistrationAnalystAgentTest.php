<?php

namespace Tests\Unit\Ai;

use App\Infrastructure\Ai\Agents\RegistrationAnalystAgent;
use Tests\TestCase;

class RegistrationAnalystAgentTest extends TestCase
{
    private RegistrationAnalystAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new RegistrationAnalystAgent();
    }

    public function test_all_required_present_returns_complete(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'sci-fi and fantasy', 'style' => 'action oriented'],
            ['preferences', 'style'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_required']);
    }

    public function test_missing_one_required_returns_incomplete(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'sci-fi and fantasy'],
            ['preferences', 'style'],
        );

        $this->assertFalse($result['is_complete']);
        $this->assertContains('style', $result['missing_required']);
        $this->assertNotContains('preferences', $result['missing_required']);
    }

    public function test_trivial_value_treated_as_missing(): void
    {
        // "a" has length < 3 → should not count as complete
        $result = $this->agent->analyze(
            ['preferences' => 'a', 'style' => 'ok'],
            ['preferences', 'style'],
        );

        $this->assertFalse($result['is_complete']);
        $this->assertContains('preferences', $result['missing_required']);
        $this->assertContains('style', $result['missing_required']); // "ok" len=2 < 3
    }

    public function test_exactly_three_chars_counts_as_complete(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'rpg'],
            ['preferences'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_required']);
    }

    public function test_empty_string_treated_as_missing(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => ''],
            ['preferences'],
        );

        $this->assertFalse($result['is_complete']);
        $this->assertContains('preferences', $result['missing_required']);
    }

    public function test_whitespace_only_treated_as_missing(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => '   '],
            ['preferences'],
        );

        $this->assertFalse($result['is_complete']);
        $this->assertContains('preferences', $result['missing_required']);
    }

    public function test_optional_fields_reported_separately(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'sci-fi'],
            ['preferences'],
            ['red_lines'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_required']);
        $this->assertContains('red_lines', $result['missing_optional']);
    }

    public function test_complete_optional_not_in_missing_optional(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'sci-fi', 'red_lines' => 'no gore please'],
            ['preferences'],
            ['red_lines'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertEmpty($result['missing_optional']);
    }

    public function test_no_required_fields_always_complete(): void
    {
        $result = $this->agent->analyze(
            [],
            [],
        );

        $this->assertTrue($result['is_complete']);
    }

    public function test_complete_fields_returned_in_output(): void
    {
        $result = $this->agent->analyze(
            ['preferences' => 'sci-fi', 'style' => 'action packed'],
            ['preferences', 'style'],
        );

        $this->assertArrayHasKey('complete_fields', $result);
        $this->assertArrayHasKey('preferences', $result['complete_fields']);
        $this->assertArrayHasKey('style', $result['complete_fields']);
    }

    public function test_keys_not_in_required_or_optional_still_appear_in_complete_fields(): void
    {
        // Extra keys in extracted (e.g. form fields) with value len >= 3 are tracked as complete
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy', 'activity_level' => '100'],
            ['preferences'],
        );

        $this->assertTrue($result['is_complete']);
        $this->assertArrayHasKey('activity_level', $result['complete_fields']);
    }

    public function test_short_numeric_form_values_not_in_complete_fields(): void
    {
        // Single-digit values like '4' (len=1) don't meet MIN_VALUE_LENGTH — not tracked
        $result = $this->agent->analyze(
            ['preferences' => 'fantasy', 'activity_level' => '4'],
            ['preferences'],
        );

        $this->assertTrue($result['is_complete']); // preferences is still present
        $this->assertArrayNotHasKey('activity_level', $result['complete_fields']);
    }
}
