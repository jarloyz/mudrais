<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');               // alpha | beta | gamma
            $table->string('app_id')->unique();   // Discord application ID
            $table->tinyInteger('tier');          // 1=interactions, 2=threads, 3=voice
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('slug');
            $table->index('app_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_bots');
    }
};
