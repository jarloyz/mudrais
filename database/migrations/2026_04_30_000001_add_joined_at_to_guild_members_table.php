<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guild_members', function (Blueprint $table) {
            $table->timestamp('joined_at')->nullable()->after('guild_id');
        });
    }

    public function down(): void
    {
        Schema::table('guild_members', function (Blueprint $table) {
            $table->dropColumn('joined_at');
        });
    }
};
