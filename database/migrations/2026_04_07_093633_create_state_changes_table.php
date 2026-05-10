<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('state_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope_type'); // global|character|scene|location|event
            $table->uuid('scope_id')->nullable();
            $table->text('change');
            $table->integer('severity')->default(1); // 1-5
            $table->timestamps();
        });

        Schema::create('state_change_tags', function (Blueprint $table) {
            $table->uuid('change_id');
            $table->uuid('tag_id');

            $table->primary(['change_id', 'tag_id']);
            $table->foreign('change_id')->references('id')->on('state_changes')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_change_tags');
        Schema::dropIfExists('state_changes');
    }
};
