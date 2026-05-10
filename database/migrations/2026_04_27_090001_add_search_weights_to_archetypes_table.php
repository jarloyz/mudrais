<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->jsonb('search_weights')->nullable()->after('is_hub_indexed');
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropColumn('search_weights');
        });
    }
};
