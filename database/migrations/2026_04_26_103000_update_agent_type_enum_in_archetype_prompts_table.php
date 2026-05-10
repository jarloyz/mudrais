<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_prompts DROP CONSTRAINT IF EXISTS archetype_prompts_agent_type_check");
            DB::statement("ALTER TABLE archetype_prompts ADD CONSTRAINT archetype_prompts_agent_type_check CHECK (agent_type IN ('gatekeeper', 'optimizer', 'player_profile', 'vault'))");
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_prompts DROP CONSTRAINT IF EXISTS archetype_prompts_agent_type_check");
            DB::statement("ALTER TABLE archetype_prompts ADD CONSTRAINT archetype_prompts_agent_type_check CHECK (agent_type IN ('gatekeeper', 'optimizer'))");
        }
    }
};
