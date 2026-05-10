<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qdrant_logs', function (Blueprint $table) {
            $table->text('query_text')->nullable()->after('matches_count');
            $table->text('top_result')->nullable()->after('query_text');
            $table->float('top_score')->nullable()->after('top_result');
        });
    }

    public function down(): void
    {
        Schema::table('qdrant_logs', function (Blueprint $table) {
            $table->dropColumn(['query_text', 'top_result', 'top_score']);
        });
    }
};
