<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vault_id');
            $table->string('title')->default('');
            $table->integer('chapter')->default(1);
            $table->integer('scene_number')->default(1);
            $table->string('status', 20)->default('draft');
            $table->uuid('location_id')->nullable();
            $table->text('objective')->nullable();
            $table->text('constraints')->nullable();
            $table->text('draft')->nullable();
            $table->timestamps();

            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
