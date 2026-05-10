<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->json('content_raw')->nullable()->after('objective');
            $table->string('semantic_tag_query', 500)->nullable()->after('content_raw');
        });

        Schema::table('avatars', function (Blueprint $table) {
            $table->json('content_raw')->nullable()->after('is_lfg');
            $table->string('semantic_tag_query', 500)->nullable()->after('content_raw');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['content_raw', 'semantic_tag_query']);
        });

        Schema::table('avatars', function (Blueprint $table) {
            $table->dropColumn(['content_raw', 'semantic_tag_query']);
        });
    }
};
