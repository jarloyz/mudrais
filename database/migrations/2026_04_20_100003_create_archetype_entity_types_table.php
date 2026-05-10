<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archetype_entity_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('archetype_id')->constrained()->cascadeOnDelete();
            $table->enum('entity', ['avatar', 'activity']);
            $table->string('type_key');
            $table->string('type_label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['archetype_id', 'entity', 'type_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_entity_types');
    }
};
