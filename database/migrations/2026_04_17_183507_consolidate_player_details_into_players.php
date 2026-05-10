<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->string('nationality', 100)->nullable()->after('country_code');
            $table->json('schedule')->nullable()->after('schedule_raw');
            $table->json('red_lines')->nullable()->after('schedule');
            $table->json('affinities')->nullable()->after('red_lines');
            $table->text('raw_profile')->nullable()->after('narrative_style_text');
            $table->boolean('is_vectorized')->default(false)->after('raw_profile');
        });

        // Migrate existing data from player_details → players
        if (Schema::hasTable('player_details')) {
            DB::table('player_details')->get()->each(function (object $detail): void {
                DB::table('players')->where('id', $detail->player_id)->update([
                    'nationality'   => $detail->nationality,
                    'schedule'      => $detail->schedule,
                    'red_lines'     => $detail->red_lines,
                    'affinities'    => $detail->affinities,
                    'raw_profile'   => $detail->raw_profile,
                    'is_vectorized' => $detail->is_vectorized,
                ]);
            });

            Schema::drop('player_details');
        }
    }

    public function down(): void
    {
        Schema::create('player_details', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->string('nationality', 100)->nullable();
            $table->json('schedule')->nullable();
            $table->json('red_lines')->nullable();
            $table->json('affinities')->nullable();
            $table->text('raw_profile')->nullable();
            $table->boolean('is_vectorized')->default(false);
            $table->timestamps();
        });

        DB::table('players')->whereNotNull('raw_profile')
            ->orWhereNotNull('nationality')
            ->get()
            ->each(function (object $player): void {
                DB::table('player_details')->insert([
                    'player_id'     => $player->id,
                    'nationality'   => $player->nationality,
                    'schedule'      => $player->schedule,
                    'red_lines'     => $player->red_lines,
                    'affinities'    => $player->affinities,
                    'raw_profile'   => $player->raw_profile,
                    'is_vectorized' => $player->is_vectorized,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn(['nationality', 'schedule', 'red_lines', 'affinities', 'raw_profile', 'is_vectorized']);
        });
    }
};
