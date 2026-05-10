<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetype_drafts', function (Blueprint $table) {
            $table->text('semantic_tag_query')->nullable()->after('optimized_text_en');
        });
    }

    public function down(): void
    {
        Schema::table('archetype_drafts', function (Blueprint $table) {
            $table->dropColumn('semantic_tag_query');
        });
    }
};
