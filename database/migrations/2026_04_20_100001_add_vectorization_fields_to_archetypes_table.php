<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->string('slug')->unique()->nullable()->after('name');
            $table->text('summary')->nullable()->after('slug');
            $table->json('archetype_style_vector')->nullable()->after('registration_modal_schema');
            $table->uuid('archetype_hub_qdrant_id')->nullable()->after('archetype_style_vector');
            $table->boolean('is_hub_indexed')->default(false)->after('archetype_hub_qdrant_id');
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropColumn([
                'slug',
                'summary',
                'archetype_style_vector',
                'archetype_hub_qdrant_id',
                'is_hub_indexed',
            ]);
        });
    }
};
