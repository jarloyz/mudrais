<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_permissions_are_created_correctly()
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roles = ['viewer', 'moderator', 'game_master', 'super_admin'];
        foreach ($roles as $roleName) {
            $this->assertDatabaseHas('roles', ['name' => $roleName, 'guard_name' => 'web']);
        }

        $allPermissions = Permission::count();
        $this->assertGreaterThan(0, $allPermissions);

        $superAdmin = Role::findByName('super_admin', 'web');
        $superAdmin->load('permissions');

        $this->assertCount($allPermissions, $superAdmin->permissions);
    }
}
