<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taggables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_tag_id')->constrained('canonical_tags')->cascadeOnDelete();
            $table->uuid('taggable_id');
            $table->string('taggable_type');
            $table->string('tag_context'); // 'red_line' | 'preference' | 'content'
            $table->timestamps();

            $table->index(['taggable_id', 'taggable_type', 'tag_context'], 'taggables_index');
            $table->unique(['canonical_tag_id', 'taggable_id', 'taggable_type', 'tag_context'], 'taggables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
    }
};
