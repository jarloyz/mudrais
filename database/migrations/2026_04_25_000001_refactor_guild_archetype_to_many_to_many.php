<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archetype_guild', function (Blueprint $table) {
            $table->foreignUuid('archetype_id')->constrained('archetypes')->cascadeOnDelete();
            $table->foreignUuid('guild_id')->constrained('guilds')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['archetype_id', 'guild_id']);
        });

        // Migrate existing archetype_id values to pivot before dropping the column.
        if (Schema::hasColumn('guilds', 'archetype_id')) {
            $rows = DB::table('guilds')
                ->whereNotNull('archetype_id')
                ->select('id', 'archetype_id')
                ->get();

            foreach ($rows as $row) {
                DB::table('archetype_guild')->insert([
                    'archetype_id' => $row->archetype_id,
                    'guild_id'     => $row->id,
                    'is_primary'   => true,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            Schema::table('guilds', function (Blueprint $table) {
                $table->dropForeign(['archetype_id']);
                $table->dropColumn('archetype_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->foreignUuid('archetype_id')->nullable()->constrained('archetypes');
        });

        // Restore archetype_id from the primary pivot entry.
        $rows = DB::table('archetype_guild')->where('is_primary', true)->get();
        foreach ($rows as $row) {
            DB::table('guilds')
                ->where('id', $row->guild_id)
                ->update(['archetype_id' => $row->archetype_id]);
        }

        Schema::dropIfExists('archetype_guild');
    }
};
