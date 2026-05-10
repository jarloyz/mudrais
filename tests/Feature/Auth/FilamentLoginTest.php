<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Spatie Permission cache clearing and roles creation
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_page_is_accessible()
    {
        $response = $this->get('/app/login');

        $response->assertStatus(200);
    }

    public function test_user_without_super_admin_role_is_denied_access_to_panel()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);

        $panelResponse = $this->actingAs($user)->get('/app');
        $panelResponse->assertStatus(403);
    }

    public function test_super_admin_user_can_access_panel()
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole('super_admin');

        $panelResponse = $this->actingAs($user)->get('/app');
        $panelResponse->assertStatus(200);
    }
}
