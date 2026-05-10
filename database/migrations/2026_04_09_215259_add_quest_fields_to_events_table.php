<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('quest_id')->nullable()->after('location_id');
            $table->string('type', 50)->nullable()->after('status');
            $table->integer('priority')->default(0)->after('importance');

            $table->foreign('quest_id')->references('id')->on('quests')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['quest_id']);
            $table->dropColumn(['quest_id', 'type', 'priority']);
        });
    }
};
