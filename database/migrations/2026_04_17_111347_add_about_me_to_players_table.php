<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->text('about_me')->nullable()->after('narrative_style_text');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('about_me');
        });
    }
};
