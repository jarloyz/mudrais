<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('vault_id')->nullable()->after('id');
            $table->uuid('location_id')->nullable()->after('vault_id');

            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['vault_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['vault_id', 'location_id']);
        });
    }
};
