<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE archetype_prompts DROP CONSTRAINT archetype_prompts_agent_type_check');
        DB::statement("
            ALTER TABLE archetype_prompts
            ADD CONSTRAINT archetype_prompts_agent_type_check
            CHECK (agent_type::text = ANY (ARRAY[
                'gatekeeper'::text,
                'optimizer'::text,
                'player_profile'::text,
                'vault'::text,
                'context_injection'::text
            ]))
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE archetype_prompts DROP CONSTRAINT archetype_prompts_agent_type_check');
        DB::statement("
            ALTER TABLE archetype_prompts
            ADD CONSTRAINT archetype_prompts_agent_type_check
            CHECK (agent_type::text = ANY (ARRAY[
                'gatekeeper'::text,
                'optimizer'::text,
                'player_profile'::text,
                'vault'::text
            ]))
        ");
    }
};
