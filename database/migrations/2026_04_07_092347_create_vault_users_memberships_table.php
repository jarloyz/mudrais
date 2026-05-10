<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_users_memberships', function (Blueprint $table) {
            $table->uuid('vault_id');
            $table->uuid('user_id'); // Reference to users table

            $table->string('role', 20)->default('reader'); // owner, editor, reader
            $table->string('status', 20)->default('active'); // active, suspended, invited
            $table->uuid('active_continuity_id')->nullable(); // Will be an FK to continuities

            $table->timestamps();

            $table->primary(['vault_id', 'user_id']);

            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_users_memberships');
    }
};
