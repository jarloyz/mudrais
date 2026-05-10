<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taggables', function (Blueprint $table): void {
            $table->string('original_text', 500)->nullable()->after('tag_context');
        });
    }

    public function down(): void
    {
        Schema::table('taggables', function (Blueprint $table): void {
            $table->dropColumn('original_text');
        });
    }
};
