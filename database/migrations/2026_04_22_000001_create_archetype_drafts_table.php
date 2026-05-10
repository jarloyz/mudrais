<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archetype_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('input_name', 255);
            $table->text('input_text');
            $table->string('name_es', 255)->nullable();
            $table->string('name_en', 255)->nullable();
            $table->string('slug', 255)->nullable()->index();
            $table->text('optimized_text_en')->nullable();
            $table->json('style_vector')->nullable();
            $table->json('suggested_tags')->nullable();
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignUuid('archetype_id')->nullable()->constrained('archetypes')->nullOnDelete();
            $table->text('processing_error')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archetype_drafts');
    }
};
