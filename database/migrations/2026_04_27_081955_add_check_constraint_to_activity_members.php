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
        // En PostgreSQL añadimos el constraint de chequeo para asegurar que solo uno de los dos campos esté lleno
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE activity_members
                ADD CONSTRAINT chk_activity_member_one_participant
                CHECK (
                    (avatar_id IS NOT NULL AND player_archetype_profile_id IS NULL)
                    OR
                    (avatar_id IS NULL AND player_archetype_profile_id IS NOT NULL)
                )
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE activity_members DROP CONSTRAINT IF EXISTS chk_activity_member_one_participant');
        }
    }
};
