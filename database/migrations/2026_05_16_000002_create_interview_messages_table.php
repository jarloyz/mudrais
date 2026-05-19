<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('thread_id');
            $table->string('discord_id');
            $table->string('guild_id');
            $table->text('content');
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->index(['thread_id', 'is_processed']);
            $table->index('discord_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_messages');
    }
};
