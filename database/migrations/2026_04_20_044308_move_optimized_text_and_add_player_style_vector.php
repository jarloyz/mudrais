<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add player_style_vector column
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->json('player_style_vector')->nullable();
        });

        // Migrate existing optimized_text records if they exist
        if (Schema::hasColumn('player_archetype_profiles', 'optimized_text')) {
            $profiles = DB::table('player_archetype_profiles')
                ->whereNotNull('optimized_text')
                ->get();

            foreach ($profiles as $profile) {
                DB::table('optimizables')->insert([
                    'optimizable_type' => \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::class,
                    'optimizable_id' => $profile->id,
                    'optimized_text' => $profile->optimized_text,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Drop optimized_text column
            Schema::table('player_archetype_profiles', function (Blueprint $table) {
                $table->dropColumn('optimized_text');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->text('optimized_text')->nullable();
            $table->dropColumn('player_style_vector');
        });

        // Copy back optimized_text if needed (naive approach)
        $optimizables = DB::table('optimizables')
            ->where('optimizable_type', \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::class)
            ->get();

        foreach ($optimizables as $optimizable) {
            DB::table('player_archetype_profiles')
                ->where('id', $optimizable->optimizable_id)
                ->update(['optimized_text' => $optimizable->optimized_text]);
        }

        DB::table('optimizables')
            ->where('optimizable_type', \App\Domains\Matchmaking\Models\PlayerArchetypeProfile::class)
            ->delete();
    }
};
