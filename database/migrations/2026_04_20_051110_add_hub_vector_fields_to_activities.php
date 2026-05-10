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
        Schema::table('activities', function (Blueprint $table) {
            $table->uuid('activity_hub_qdrant_id')->nullable();
            $table->boolean('is_hub_indexed')->default(false);
            $table->boolean('requires_avatar')->default(true);
            $table->text('activity_description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn([
                'activity_hub_qdrant_id',
                'is_hub_indexed',
                'requires_avatar',
                'activity_description'
            ]);
        });
    }
};
