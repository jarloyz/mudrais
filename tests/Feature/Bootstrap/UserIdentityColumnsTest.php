<?php

namespace Tests\Feature\Bootstrap;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserIdentityColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_identity_provider_and_uuid(): void
    {
        $user = User::factory()->withIdentity('local')->create();

        $this->assertNotNull($user->identity_provider);
        $this->assertNotNull($user->identity_uuid);
        $this->assertTrue($user->hasExternalIdentity());

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'identity_provider' => 'local',
            'identity_uuid' => $user->identity_uuid,
        ]);
    }
}
