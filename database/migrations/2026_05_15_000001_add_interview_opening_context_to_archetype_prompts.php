<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALL_TYPES = [
        'gatekeeper', 'optimizer', 'player_profile', 'vault', 'context_injection',
        'interviewer', 'interview_opening', 'interview_gatekeeper', 'interview_optimizer',
        'interview_opening_avatar', 'interview_opening_activity',
    ];

    private const PREV_TYPES = [
        'gatekeeper', 'optimizer', 'player_profile', 'vault', 'context_injection',
        'interviewer', 'interview_opening', 'interview_gatekeeper', 'interview_optimizer',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->pgsqlExpand(self::ALL_TYPES);
        } else {
            $this->schemaChange(self::ALL_TYPES);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->pgsqlExpand(self::PREV_TYPES);
        } else {
            $this->schemaChange(self::PREV_TYPES);
        }
    }

    private function pgsqlExpand(array $types): void
    {
        $list = implode(",\n                ", array_map(fn($t) => "'{$t}'::text", $types));

        DB::statement('ALTER TABLE archetype_prompts DROP CONSTRAINT archetype_prompts_agent_type_check');
        DB::statement("
            ALTER TABLE archetype_prompts
            ADD CONSTRAINT archetype_prompts_agent_type_check
            CHECK (agent_type::text = ANY (ARRAY[
                {$list}
            ]))
        ");
    }

    private function schemaChange(array $types): void
    {
        Schema::table('archetype_prompts', function (Blueprint $table) use ($types) {
            $table->enum('agent_type', $types)->change();
        });
    }
};
