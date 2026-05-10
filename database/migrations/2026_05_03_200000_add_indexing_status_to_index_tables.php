<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->string('indexing_status', 20)->default('pending')->after('is_hub_indexed');
            $table->text('index_error')->nullable()->after('indexing_status');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->string('indexing_status', 20)->default('pending')->after('is_hub_indexed');
            $table->text('index_error')->nullable()->after('indexing_status');
        });

        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->string('indexing_status', 20)->default('pending')->after('is_vectorized');
            $table->text('index_error')->nullable()->after('indexing_status');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->dropColumn(['indexing_status', 'index_error']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['indexing_status', 'index_error']);
        });

        Schema::table('player_archetype_profiles', function (Blueprint $table) {
            $table->dropColumn(['indexing_status', 'index_error']);
        });
    }
};
