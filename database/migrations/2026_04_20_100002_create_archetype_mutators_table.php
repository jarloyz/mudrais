<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archetype_mutators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('archetype_id')->constrained()->cascadeOnDelete();
            $table->string('context');
            $table->string('field_key');
            $table->string('field_label');
            $table->enum('field_type', ['text', 'select', 'number', 'boolean']);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['archetype_id', 'context', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_mutators');
    }
};
