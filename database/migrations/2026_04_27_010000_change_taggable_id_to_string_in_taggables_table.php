<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL requires explicit casting for bigint to varchar
            DB::statement('ALTER TABLE taggables DROP CONSTRAINT IF EXISTS taggables_unique');
            DB::statement('DROP INDEX IF EXISTS taggables_index');
            DB::statement('ALTER TABLE taggables ALTER COLUMN taggable_id TYPE VARCHAR(255) USING taggable_id::varchar');
        } else {
            Schema::table('taggables', function (Blueprint $table) {
                $table->dropUnique('taggables_unique');
                $table->dropIndex('taggables_index');
            });

            Schema::table('taggables', function (Blueprint $table) {
                $table->string('taggable_id')->change();
            });
        }

        Schema::table('taggables', function (Blueprint $table) {
            $table->index(['taggable_id', 'taggable_type', 'tag_context'], 'taggables_index');
            $table->unique(['canonical_tag_id', 'taggable_id', 'taggable_type', 'tag_context'], 'taggables_unique');
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE taggables DROP CONSTRAINT IF EXISTS taggables_unique');
            DB::statement('DROP INDEX IF EXISTS taggables_index');
            // This might fail if there are UUIDs already stored
            DB::statement('ALTER TABLE taggables ALTER COLUMN taggable_id TYPE BIGINT USING taggable_id::bigint');
        } else {
            Schema::table('taggables', function (Blueprint $table) {
                $table->dropUnique('taggables_unique');
                $table->dropIndex('taggables_index');
            });

            Schema::table('taggables', function (Blueprint $table) {
                $table->uuid('taggable_id')->change();
            });
        }

        Schema::table('taggables', function (Blueprint $table) {
            $table->index(['taggable_id', 'taggable_type', 'tag_context'], 'taggables_index');
            $table->unique(['canonical_tag_id', 'taggable_id', 'taggable_type', 'tag_context'], 'taggables_unique');
        });
    }
};
