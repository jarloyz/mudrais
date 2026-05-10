<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->uuid('scene_id')->nullable();
            $table->uuid('context_id')->nullable();
            $table->string('date_label')->nullable();
            $table->uuid('subject_character_id')->nullable();
            $table->text('summary')->nullable();
            $table->string('source')->nullable();

            // From 011_events_brief_detail
            $table->text('brief')->nullable();
            $table->text('detail')->nullable();
            $table->integer('importance')->default(1);
            $table->string('status', 20)->default('active');
            $table->integer('cooldown_turns')->default(0);
            $table->integer('last_fired_turn')->nullable();

            $table->timestamps();

            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('set null');
            $table->foreign('subject_character_id')->references('id')->on('characters')->onDelete('set null');
        });

        Schema::create('event_characters', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->uuid('character_id');
            $table->string('role')->nullable();

            $table->primary(['event_id', 'character_id']);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
        });

        Schema::create('event_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('title');
            $table->integer('sort_order')->default(0);

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });

        Schema::create('event_bullets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('section_id');
            $table->text('text');
            $table->integer('sort_order')->default(0);

            $table->foreign('section_id')->references('id')->on('event_sections')->onDelete('cascade');
        });

        Schema::create('event_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->text('action');
            $table->text('consequence')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_milestones');
        Schema::dropIfExists('event_bullets');
        Schema::dropIfExists('event_sections');
        Schema::dropIfExists('event_characters');
        Schema::dropIfExists('events');
    }
};
