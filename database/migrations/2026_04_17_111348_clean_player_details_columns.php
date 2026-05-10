<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_details', function (Blueprint $table): void {
            $table->dropColumn(['age', 'experience_level', 'verbosity']);
        });
    }

    public function down(): void
    {
        Schema::table('player_details', function (Blueprint $table): void {
            $table->integer('age')->nullable();
            $table->string('experience_level')->nullable();
            $table->string('verbosity')->nullable();
        });
    }
};
