<?php

namespace Tests\Unit;

use Tests\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UuidV7Test extends TestCase
{
    use RefreshDatabase;

    public function test_can_generate_uuidv7(): void
    {
        $uuid = Uuid::v7();
        $this->assertNotNull($uuid);
        $this->assertInstanceOf(UuidV7::class, $uuid);
    }

    public function test_user_uses_uuidv7(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->id);
        $this->assertTrue(Uuid::isValid($user->id));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($user->id));
    }
}
