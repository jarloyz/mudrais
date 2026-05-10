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
        Schema::table('scenes', function (Blueprint $table) {
            if (!Schema::hasColumn('scenes', 'status')) {
                $table->enum('status', ['draft', 'ready', 'archived'])->default('draft');
            }
        });

        if (!Schema::hasTable('scene_users')) {
            Schema::create('scene_users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('scene_id');
                $table->foreign('scene_id')->references('id')->on('scenes')->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->enum('role', ['admin', 'guest'])->default('guest');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scene_users');
        Schema::table('scenes', function (Blueprint $table) {
            if (Schema::hasColumn('scenes', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
