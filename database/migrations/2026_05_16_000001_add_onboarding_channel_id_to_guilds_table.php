<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            // Canal de Discord donde el bot beta crea hilos privados de entrevista.
            // NULL para guilds que solo usan alpha (sin onboarding beta).
            $table->string('onboarding_channel_id')->nullable()->after('owner_discord_id');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn('onboarding_channel_id');
        });
    }
};
