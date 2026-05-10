<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_mutators DROP CONSTRAINT IF EXISTS archetype_mutators_field_type_check");
            DB::statement("ALTER TABLE archetype_mutators ADD CONSTRAINT archetype_mutators_field_type_check CHECK (field_type IN ('text', 'text_short', 'text_long', 'select', 'range', 'boolean'))");
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_mutators DROP CONSTRAINT IF EXISTS archetype_mutators_field_type_check");
            DB::statement("ALTER TABLE archetype_mutators ADD CONSTRAINT archetype_mutators_field_type_check CHECK (field_type IN ('text', 'select', 'range', 'boolean'))");
        }
    }
};
