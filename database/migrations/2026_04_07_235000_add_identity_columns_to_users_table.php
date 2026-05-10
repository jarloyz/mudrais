<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('identity_provider')->nullable()->after('email_verified_at');
            $table->uuid('identity_uuid')->nullable()->after('identity_provider');

            $table->unique(['identity_provider', 'identity_uuid'], 'users_identity_provider_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_identity_provider_uuid_unique');
            $table->dropColumn(['identity_provider', 'identity_uuid']);
        });
    }
};
