<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetype_mutators', function (Blueprint $table) {
            $table->string('field_placeholder', 255)->nullable()->after('field_label');
        });
    }

    public function down(): void
    {
        Schema::table('archetype_mutators', function (Blueprint $table) {
            $table->dropColumn('field_placeholder');
        });
    }
};
