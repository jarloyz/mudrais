<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedTinyInteger('age')->nullable()->after('username');
            $table->string('country_code', 2)->nullable()->after('age');
            $table->unsignedTinyInteger('experience_level')->nullable()->after('country_code'); // 1-5
            $table->unsignedTinyInteger('verbosity_level')->nullable()->after('experience_level'); // 1-5
            $table->string('schedule_raw')->nullable()->after('verbosity_level');
            $table->text('narrative_style_text')->nullable()->after('schedule_raw');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn([
                'age', 'country_code', 'experience_level',
                'verbosity_level', 'schedule_raw', 'narrative_style_text',
            ]);
        });
    }
};
