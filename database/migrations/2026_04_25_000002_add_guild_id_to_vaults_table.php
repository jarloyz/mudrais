<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            $table->foreignUuid('guild_id')
                ->nullable()
                ->after('archetype_id')
                ->constrained('guilds')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            $table->dropForeign(['guild_id']);
            $table->dropColumn('guild_id');
        });
    }
};
