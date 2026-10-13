<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function superAdmin(): User
    {
        return User::where('username', 'superadmin')->first();
    }

    private function adminUser(): User
    {
        return User::factory()->create()->assignRole('Admin');
    }

    public function test_all_admin_pages_render_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin());
        $role = Role::findByName('Admin');
        $user = User::factory()->create();

        foreach (['/dashboard', '/profile', '/admin/users', '/admin/users/create', "/admin/users/{$user->id}/edit",
            '/admin/roles', '/admin/roles/create', "/admin/roles/{$role->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_user_without_permission_cannot_access_user_management(): void
    {
        $staff = User::factory()->create()->assignRole('Staff Gudang');

        $this->actingAs($staff)->get('/dashboard')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/users/create')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_authorized_user_can_create_account_with_role_and_permission(): void
    {
        $staffRole = Role::findByName('Staff Gudang');

        $this->actingAs($this->superAdmin())->post('/admin/users', [
            'name' => 'Siti Aminah',
            'username' => 'siti',
            'email' => 'siti@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'is_active' => '1',
            'roles' => [$staffRole->id],
            'permissions' => ['users.view'],
        ])->assertRedirect(route('admin.users.index'));

        $siti = User::where('username', 'siti')->first();
        $this->assertTrue($siti->hasRole('Staff Gudang'));
        $this->assertTrue($siti->can('users.view'));
        $this->assertFalse($siti->can('users.create'));
        $this->assertEquals($this->superAdmin()->id, $siti->created_by);
    }

    public function test_admin_cannot_grant_permission_they_do_not_have(): void
    {
        $this->actingAs($this->adminUser())->post('/admin/users', [
            'name' => 'Eskalasi',
            'username' => 'eskalasi',
            'email' => 'eskalasi@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'permissions' => ['roles.delete'],
        ])->assertSessionHasErrors('permissions.0');

        $this->assertDatabaseMissing('users', ['username' => 'eskalasi']);
    }

    public function test_admin_cannot_assign_super_admin_role(): void
    {
        $super = Role::findByName('Super Admin');

        $this->actingAs($this->adminUser())->post('/admin/users', [
            'name' => 'Eskalasi',
            'username' => 'eskalasi',
            'email' => 'eskalasi@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'roles' => [$super->id],
        ])->assertSessionHasErrors('roles.0');
    }

    public function test_admin_cannot_edit_super_admin_account(): void
    {
        $this->actingAs($this->adminUser())
            ->get("/admin/users/{$this->superAdmin()->id}/edit")
            ->assertForbidden();
    }

    public function test_editing_own_account_keeps_roles_and_status(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => 'Super Admin Baru',
            'username' => 'superadmin',
            'email' => $admin->email,
            'is_active' => '0',
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'));

        $admin->refresh();
        $this->assertSame('Super Admin Baru', $admin->name);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isSuperAdmin());
    }

    public function test_user_cannot_delete_or_deactivate_self(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertForbidden();
        $this->patch("/admin/users/{$admin->id}/status")->assertForbidden();
        $this->assertModelExists($admin);
    }

    public function test_super_admin_can_toggle_and_delete_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->superAdmin());

        $this->patch("/admin/users/{$user->id}/status");
        $this->assertFalse($user->fresh()->is_active);

        $this->delete("/admin/users/{$user->id}")->assertRedirect(route('admin.users.index'));
        $this->assertModelMissing($user);
    }
}
