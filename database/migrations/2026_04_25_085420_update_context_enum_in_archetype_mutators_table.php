<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('archetype_mutators')->where('context', 'vault')->delete();

        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_mutators DROP CONSTRAINT IF EXISTS archetype_mutators_context_check");
            DB::statement("ALTER TABLE archetype_mutators ADD CONSTRAINT archetype_mutators_context_check CHECK (context IN ('registration', 'activities_vibe', 'avatar_context'))");
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_mutators DROP CONSTRAINT IF EXISTS archetype_mutators_context_check");
            DB::statement("ALTER TABLE archetype_mutators ADD CONSTRAINT archetype_mutators_context_check CHECK (context IN ('registration', 'vault'))");
        }
    }
};
