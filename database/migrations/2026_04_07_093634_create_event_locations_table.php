<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_locations', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->uuid('location_id');
            $table->string('role')->nullable();

            $table->primary(['event_id', 'location_id']);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_locations');
    }
};
