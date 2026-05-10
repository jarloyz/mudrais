<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetype_mutators', function (Blueprint $table) {
            $table->string('modal_group', 50)->nullable()->after('field_placeholder');
            $table->string('storage_mode', 10)->default('raw')->after('modal_group');
        });

        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_mutators ADD CONSTRAINT archetype_mutators_storage_mode_check CHECK (storage_mode IN ('raw', 'semantic', 'both'))");
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'sqlite' && config('database.default') !== 'testing') {
            DB::statement("ALTER TABLE archetype_mutators DROP CONSTRAINT IF EXISTS archetype_mutators_storage_mode_check");
        }

        Schema::table('archetype_mutators', function (Blueprint $table) {
            $table->dropColumn(['modal_group', 'storage_mode']);
        });
    }
};
