<?php

namespace Tests\Unit\Matchmaking;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use PHPUnit\Framework\TestCase;

class ArchetypeEntityTypeTest extends TestCase
{
    public function test_get_mutator_context_for_activity(): void
    {
        $entityType = new ArchetypeEntityType(['entity' => 'activity']);
        $this->assertEquals('activities_vibe', $entityType->getMutatorContext());
    }

    public function test_get_mutator_context_for_avatar(): void
    {
        $entityType = new ArchetypeEntityType(['entity' => 'avatar']);
        $this->assertEquals('avatar_context', $entityType->getMutatorContext());
    }

    public function test_get_mutator_context_default(): void
    {
        $entityType = new ArchetypeEntityType(['entity' => 'unknown']);
        $this->assertEquals('registration', $entityType->getMutatorContext());
    }

    public function test_system_prompt_is_fillable(): void
    {
        $entityType = new ArchetypeEntityType(['system_prompt' => 'You are an AI']);
        $this->assertEquals('You are an AI', $entityType->system_prompt);
    }
}
