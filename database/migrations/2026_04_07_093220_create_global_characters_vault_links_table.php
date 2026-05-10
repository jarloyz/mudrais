<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_characters', function (Blueprint $table) {
            $table->uuid('vault_id');
            $table->uuid('character_id');

            $table->string('status', 20)->default('active'); // active|disabled|archived
            $table->string('source', 50)->nullable(); // import_vault|base_lib|manual|legacy_vault_id

            $table->timestamps();

            $table->primary(['vault_id', 'character_id']);
            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_characters');
    }
};
