<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tags', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->string('tag');
            $table->integer('weight')->default(1);

            $table->primary(['event_id', 'tag']);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tags');
    }
};
