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
        Schema::create('character_inventory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('character_id')->index();
            $table->string('item_name');
            $table->string('category')->nullable();
            $table->integer('quantity')->default(1);
            $table->boolean('is_quick_slot')->default(false);
            $table->timestamps();

            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_inventory');
    }
};
