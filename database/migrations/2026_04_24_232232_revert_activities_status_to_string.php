<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('status_str', 20)->default('draft');
        });

        DB::table('activities')->where('status', 0)->update(['status_str' => 'draft']);
        DB::table('activities')->where('status', 1)->update(['status_str' => 'ready']);
        DB::table('activities')->where('status', 2)->update(['status_str' => 'active']);
        DB::table('activities')->where('status', 3)->update(['status_str' => 'closed']);
        DB::table('activities')->where('status', 4)->update(['status_str' => 'archived']);

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->renameColumn('status_str', 'status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversión simple a smallint si fuera necesario
    }
};
