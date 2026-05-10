<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('continuity_id')->nullable();
            $table->string('scope_type'); // scene|location|character|state|tag
            $table->string('operator')->default('eq'); // eq|in|contains|exists|not_exists
            $table->text('value_text')->nullable();
            $table->integer('weight')->default(1);
            $table->boolean('required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
        });

        Schema::create('event_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('continuity_id')->nullable();
            $table->string('effect_type')->default('state_change'); // state_change|note
            $table->string('kind')->default('state');
            $table->string('scope_type')->default('scene'); // global|scene|location|character|event
            $table->uuid('scope_id')->nullable();
            $table->text('change_text')->nullable();
            $table->integer('severity')->default(1); // 1-5
            $table->json('payload_json')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
        });

        Schema::create('event_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('continuity_id');
            $table->uuid('scene_id');
            $table->integer('turn_index');
            $table->integer('score')->default(0);
            $table->boolean('fired')->default(false);
            $table->json('reasons_json')->nullable();
            $table->integer('effects_applied')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['continuity_id', 'scene_id', 'turn_index', 'event_id']);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('continuity_id')->references('id')->on('continuities')->onDelete('cascade');
            $table->foreign('scene_id')->references('id')->on('scenes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_runs');
        Schema::dropIfExists('event_effects');
        Schema::dropIfExists('event_conditions');
    }
};
